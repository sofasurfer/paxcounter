#ifdef HAS_HTTP

#include "paxhttp.h"
#include "devicelog.h"
#include "netlog.h"

// The ESP32 has a single WiFi radio. The libpax sniffer runs it in
// promiscuous mode hopping over channels, which is incompatible with a
// station connection to an access point. We therefore pause only the WiFi
// sniffer for the short upload window (BLE scanning and the libpax report
// timer keep running), connect, POST all queued measurements, disconnect and
// resume sniffing.
//
// We do not use libpax_counter_stop()/start() for this, because stop() does
// not delete libpax' report timer, so cycling it every send interval would
// leak heap.

// libpax internals (wifiscan.cpp), declared here because libpax' wifiscan.h
// is not reliably reachable via the include path
extern int initialized_wifi; // 1 while sniffer active
void wifi_sniffer_init(uint16_t wifi_channel_switch_interval);
void wifi_sniffer_stop();
void set_wifi_country(const char *country_code);
void set_wifi_channels(uint16_t channels_map);
void switchWifiChannel(TimerHandle_t xTimer);
void set_wifi_rssi_filter(int set_rssi_threshold);

TaskHandle_t httpTask = NULL;
static QueueHandle_t HTTPSendQueue = NULL;
static uint32_t http_seq = 0;
static uint32_t boot_id = 0;
static char json[4096];

static bool pause_wifi_sniffer(void) {
  if (!initialized_wifi)
    return false;
  wifi_sniffer_stop(); // also calls esp_wifi_deinit()
  return true;
}

static void resume_wifi_sniffer(void) {
  libpax_config_t c;
  libpax_get_current_config(&c);
  // don't restart if WiFi scanning was disabled meanwhile, or if libpax
  // was restarted by someone else (e.g. remote command) in the meantime
  if (!c.wificounter || initialized_wifi)
    return;
  wifi_sniffer_init(c.wifi_channel_switch_interval);
  set_wifi_country(c.wifi_my_country_str);
  set_wifi_channels(c.wifi_channel_map);
  switchWifiChannel(NULL);
  set_wifi_rssi_filter(c.wifi_rssi_threshold);
}

static bool wifi_connect(void) {
  WiFi.setHostname(clientId);
  WiFi.mode(WIFI_STA);
  WiFi.begin(HTTP_WIFI_SSID, HTTP_WIFI_PASS);
  for (int i = 0; i < HTTP_WIFI_TIMEOUT * 10; i++) {
    if (WiFi.status() == WL_CONNECTED) {
      ESP_LOGI(TAG, "HTTP: WiFi connected, IP %s, RSSI %d",
               WiFi.localIP().toString().c_str(), WiFi.RSSI());
      return true;
    }
    vTaskDelay(pdMS_TO_TICKS(100));
  }
  ESP_LOGW(TAG, "HTTP: could not connect to WiFi '%s'", HTTP_WIFI_SSID);
  return false;
}

static void wifi_shutdown(void) {
  WiFi.disconnect(true);
  WiFi.mode(WIFI_OFF); // esp_wifi_stop() + esp_wifi_deinit()
}

// measurements taken out of the queue for the current POST; kept here until
// the server acknowledged them, so that new measurements can be enqueued
// concurrently without disturbing order
static HttpCount_t batch[HTTP_BATCH_SIZE];
static volatile int batch_n = 0;

// tops up the batch from the queue and builds the JSON body
static void build_json(void) {
  while (batch_n < HTTP_BATCH_SIZE &&
         xQueueReceive(HTTPSendQueue, &batch[batch_n], 0) == pdTRUE)
    batch_n++;

  const uint32_t now = millis();
  size_t len = snprintf(json, sizeof(json),
                        "{\"device\":\"%s\",\"boot\":%u,\"records\":[",
                        clientId, boot_id);
  for (int i = 0; i < batch_n; i++)
    len += snprintf(json + len, sizeof(json) - len,
                    "%s{\"seq\":%u,\"age\":%u,\"pax\":%u,\"wifi\":%u,"
                    "\"ble\":%u}",
                    i ? "," : "", batch[i].seq,
                    (now - batch[i].timestamp_ms) / 1000, batch[i].pax,
                    batch[i].wifi, batch[i].ble);
  snprintf(json + len, sizeof(json) - len, "]}");
}

static bool http_post(const char *body) {
  HTTPClient http;
  WiFiClient plainClient;
  WiFiClientSecure secureClient;
  bool ok = false;

  if (strncmp(HTTP_URL, "https", 5) == 0) {
#ifdef HTTP_ROOT_CA
    secureClient.setCACert(HTTP_ROOT_CA);
#else
    ESP_LOGW(TAG, "HTTP: no HTTP_ROOT_CA set, server certificate NOT checked");
    secureClient.setInsecure();
#endif
    ok = http.begin(secureClient, HTTP_URL);
  } else {
    ok = http.begin(plainClient, HTTP_URL);
  }
  if (!ok) {
    ESP_LOGE(TAG, "HTTP: invalid URL %s", HTTP_URL);
    return false;
  }

  http.setTimeout(HTTP_TIMEOUT * 1000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Key", HTTP_APIKEY);
  int code = http.POST((uint8_t *)body, strlen(body));
  if (code >= 200 && code < 300) {
    ESP_LOGI(TAG, "HTTP: upload ok (%d)", code);
    ok = true;
  } else {
    ESP_LOGW(TAG, "HTTP: upload failed, code %d %s", code,
             code < 0 ? http.errorToString(code).c_str()
                      : http.getString().c_str());
    ok = false;
  }
  http.end();
  return ok;
}

#ifdef HTTP_DEVICES
// uploads all devices seen since the last successful device upload, in
// chunks of HTTP_DEVICE_CHUNK. On failure the table is kept and retried;
// the server ignores duplicates via (device, boot, batch, mac, type).
static bool upload_devices(void) {
  DeviceEntry_t *t;
  uint32_t batch, start;
  int total = devicelog_take(&t, &batch, &start);
  const uint32_t now_s = (millis() - start) / 1000; // relative to table start
  int sent = 0, i = 0;

  while (sent < total) {
    size_t len = snprintf(json, sizeof(json),
                          "{\"device\":\"%s\",\"boot\":%u,\"batch\":%u,"
                          "\"dropped\":%u,\"devices\":[",
                          clientId, boot_id, batch, devicelog_dropped());
    int n = 0;
    for (; i < HTTP_DEVICE_TABLE && n < HTTP_DEVICE_CHUNK; i++) {
      const DeviceEntry_t *e = &t[i];
      if (!e->used)
        continue;
      len += snprintf(json + len, sizeof(json) - len,
                      "%s{\"mac\":\"%02x:%02x:%02x:%02x:%02x:%02x\",\"t\":\"%c\","
                      "%s%c%s\"n\":%u,\"first\":%u,\"last\":%u}",
                      n ? "," : "", e->mac[0], e->mac[1], e->mac[2], e->mac[3],
                      e->mac[4], e->mac[5], e->type == 1 ? 'b' : 'w',
                      e->kind ? "\"a\":\"" : "", e->kind ? e->kind : ' ',
                      e->kind ? "\"," : "", e->packets,
                      now_s > e->first_s ? now_s - e->first_s : 0,
                      now_s > e->last_s ? now_s - e->last_s : 0);
      n++;
    }
    snprintf(json + len, sizeof(json) - len, "]}");
    if (!http_post(json))
      return false;
    sent += n;
  }
  ESP_LOGI(TAG, "HTTP: %d devices uploaded (batch %u)", total, batch);
  devicelog_done();
  return true;
}
#endif

#ifdef HTTP_NETWORKS
#ifndef HTTP_NET_CHUNK
#define HTTP_NET_CHUNK 20 // max. networks per POST request (~165 bytes each)
#endif
static uint32_t last_net_upload_ms = 0;

// uploads the access points (BSSID + SSID) whose beacons were received during
// the last HTTP_NET_INTERVAL seconds. The SSID is sent hex encoded, as it may
// contain any bytes. The server keeps one row per access point, so a failed
// and repeated upload does no harm.
static bool upload_networks(void) {
  if (millis() - last_net_upload_ms < HTTP_NET_INTERVAL * 1000UL)
    return true;
  const uint32_t now = millis();
  NetEntry_t e;
  int i = 0, total = 0;
  while (i >= 0) {
    size_t len = snprintf(json, sizeof(json),
                          "{\"device\":\"%s\",\"boot\":%u,\"dropped_nets\":%u,"
                          "\"networks\":[",
                          clientId, boot_id, netlog_dropped());
    int n = 0;
    while (n < HTTP_NET_CHUNK && (i = netlog_next(i, &e)) >= 0) {
      char ssid[65];
      for (int k = 0; k < e.ssid_len; k++)
        snprintf(ssid + 2 * k, 3, "%02x", e.ssid[k]);
      ssid[2 * e.ssid_len] = 0;
      len += snprintf(json + len, sizeof(json) - len,
                      "%s{\"bssid\":\"%02x:%02x:%02x:%02x:%02x:%02x\","
                      "\"ssid\":\"%s\",\"ch\":%u,\"rssi\":%d,\"max\":%d,"
                      "\"n\":%u,\"last\":%u}",
                      n ? "," : "", e.bssid[0], e.bssid[1], e.bssid[2],
                      e.bssid[3], e.bssid[4], e.bssid[5], ssid, e.channel,
                      e.rssi_last, e.rssi_max, e.beacons,
                      (now - e.last_ms) / 1000);
      n++;
    }
    snprintf(json + len, sizeof(json) - len, "]}");
    if (n == 0 && total > 0)
      break; // nothing left after the last full chunk
    if (!http_post(json))
      return false;
    total += n;
  }
  ESP_LOGI(TAG, "HTTP: %d WiFi networks uploaded", total);
  netlog_clear();
  last_net_upload_ms = now;
  return true;
}
#endif

static void http_client_task(void *param) {
  HttpCount_t item;

  while (1) {
    // block until at least one measurement is waiting
    if (batch_n == 0 &&
        xQueuePeek(HTTPSendQueue, &item, portMAX_DELAY) != pdTRUE)
      continue;

    bool failed = false;
    bool paused = pause_wifi_sniffer();

    if (wifi_connect()) {
      while (batch_n > 0 || uxQueueMessagesWaiting(HTTPSendQueue) > 0) {
        build_json();
        if (!http_post(json)) {
          failed = true; // keep batch for next attempt
          break;
        }
        batch_n = 0;
      }
#ifdef HTTP_DEVICES
      if (!failed && !upload_devices())
        failed = true;
#endif
#ifdef HTTP_NETWORKS
      if (!failed && !upload_networks())
        failed = true;
#endif
    } else
      failed = true;

    wifi_shutdown();
    if (paused)
      resume_wifi_sniffer();

    if (failed) {
      ESP_LOGI(TAG, "HTTP: %u measurements buffered, retry in %d s",
               http_queuewaiting(), HTTP_RETRYSEC);
      vTaskDelay(pdMS_TO_TICKS(HTTP_RETRYSEC * 1000));
    }
  }
}

void http_enqueuecount(const struct count_payload_t *count) {
  if (HTTPSendQueue == NULL)
    return;
  HttpCount_t item = {++http_seq, (uint32_t)millis(), count->pax,
                      count->wifi_count, count->ble_count};
  if (xQueueSendToBack(HTTPSendQueue, &item, 0) != pdTRUE) {
    // queue full (longer offline): drop oldest measurement
    HttpCount_t dropped;
    xQueueReceive(HTTPSendQueue, &dropped, 0);
    xQueueSendToBack(HTTPSendQueue, &item, 0);
    ESP_LOGW(TAG, "HTTP: send queue full, dropped oldest measurement");
  }
}

uint32_t http_queuewaiting(void) {
  return batch_n +
         (HTTPSendQueue ? uxQueueMessagesWaiting(HTTPSendQueue) : 0);
}

void http_queuereset(void) {
  if (HTTPSendQueue)
    xQueueReset(HTTPSendQueue);
  batch_n = 0;
}

esp_err_t http_init(void) {
  WiFi.persistent(false); // don't write WiFi credentials to NVS flash
  boot_id = esp_random();

  HTTPSendQueue = xQueueCreate(HTTP_QUEUE_SIZE, sizeof(HttpCount_t));
  if (HTTPSendQueue == NULL) {
    ESP_LOGE(TAG, "Could not create HTTP send queue. Aborting.");
    return ESP_FAIL;
  }
  ESP_LOGI(TAG, "HTTP send queue created, %d entries, target %s",
           HTTP_QUEUE_SIZE, HTTP_URL);

  // TLS handshake needs a big stack
  xTaskCreatePinnedToCore(http_client_task, "httploop", 10240, NULL, 1,
                          &httpTask, 1);
  return ESP_OK;
}

void http_deinit(void) {
  if (httpTask) {
    vTaskDelete(httpTask);
    httpTask = NULL;
  }
  wifi_shutdown();
}

#endif // HAS_HTTP
