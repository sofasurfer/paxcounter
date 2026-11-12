#include "globals.h"
#include "netlog.h"
#include <esp_wifi.h>

// Linker hook: build flag -Wl,--wrap=esp_wifi_set_promiscuous_rx_cb redirects
// libpax' calls (wifiscan.cpp) to the wrapper below. We install our own
// callback, which looks at beacon frames and then calls libpax' handler with
// the unchanged frame, so counting is not affected.

extern "C" esp_err_t
__real_esp_wifi_set_promiscuous_rx_cb(wifi_promiscuous_cb_t cb);

static volatile wifi_promiscuous_cb_t libpax_cb = NULL;

#if defined(HAS_HTTP) && defined(HTTP_NETWORKS)

static NetEntry_t nets[HTTP_NET_TABLE];
static uint32_t dropped = 0;
static portMUX_TYPE netlog_mux = portMUX_INITIALIZER_UNLOCKED;

// 802.11 beacon: 24 byte MAC header, 12 byte fixed fields, then tagged IEs
#define BEACON_IE_OFFSET 36
#define FCS_LEN 4

static void netlog_beacon(const wifi_promiscuous_pkt_t *pkt) {
  const uint8_t *p = pkt->payload;
  int len = (int)pkt->rx_ctrl.sig_len - FCS_LEN;
  if (len < BEACON_IE_OFFSET + 2 || p[0] != 0x80) // type mgmt, subtype beacon
    return;

  // SSID is the first IE (tag 0); stay inside the frame
  const uint8_t *ie = p + BEACON_IE_OFFSET;
  uint8_t ssid_len = 0;
  const uint8_t *ssid = NULL;
  if (ie[0] == 0 && ie[1] <= 32 && BEACON_IE_OFFSET + 2 + ie[1] <= len) {
    ssid_len = ie[1];
    ssid = ie + 2;
    // hidden networks send a zero length or all-zero SSID
    bool zero = true;
    for (int i = 0; i < ssid_len; i++)
      if (ssid[i]) {
        zero = false;
        break;
      }
    if (zero)
      ssid_len = 0;
  }

  const uint8_t *bssid = p + 16; // addr3
  const int8_t rssi = pkt->rx_ctrl.rssi;
  const uint32_t now = millis();

  portENTER_CRITICAL(&netlog_mux);
  NetEntry_t *e = NULL, *free_slot = NULL;
  for (int i = 0; i < HTTP_NET_TABLE; i++) {
    if (!nets[i].used) {
      if (!free_slot)
        free_slot = &nets[i];
    } else if (memcmp(nets[i].bssid, bssid, 6) == 0) {
      e = &nets[i];
      break;
    }
  }
  if (!e && free_slot) {
    e = free_slot;
    memcpy(e->bssid, bssid, 6);
    e->used = 1;
    e->beacons = 0;
    e->rssi_max = rssi;
  } else if (!e) {
    dropped++;
  }
  if (e) {
    e->channel = pkt->rx_ctrl.channel;
    e->rssi_last = rssi;
    if (rssi > e->rssi_max)
      e->rssi_max = rssi;
    e->ssid_len = ssid_len;
    if (ssid_len)
      memcpy(e->ssid, ssid, ssid_len);
    if (e->beacons < UINT16_MAX)
      e->beacons++;
    e->last_ms = now;
  }
  portEXIT_CRITICAL(&netlog_mux);
}

int netlog_next(int i, NetEntry_t *out) {
  int next = -1;
  portENTER_CRITICAL(&netlog_mux);
  for (; i >= 0 && i < HTTP_NET_TABLE; i++)
    if (nets[i].used) {
      *out = nets[i];
      next = i + 1;
      break;
    }
  portEXIT_CRITICAL(&netlog_mux);
  return next;
}

void netlog_clear(void) {
  portENTER_CRITICAL(&netlog_mux);
  memset(nets, 0, sizeof(nets));
  portEXIT_CRITICAL(&netlog_mux);
}

uint32_t netlog_dropped(void) { return dropped; }

#endif

static void netlog_rx(void *buf, wifi_promiscuous_pkt_type_t type) {
#if defined(HAS_HTTP) && defined(HTTP_NETWORKS)
  if (type == WIFI_PKT_MGMT)
    netlog_beacon((const wifi_promiscuous_pkt_t *)buf);
#endif
  wifi_promiscuous_cb_t cb = libpax_cb;
  if (cb)
    cb(buf, type);
}

extern "C" esp_err_t
__wrap_esp_wifi_set_promiscuous_rx_cb(wifi_promiscuous_cb_t cb) {
  libpax_cb = cb;
  return __real_esp_wifi_set_promiscuous_rx_cb(cb ? netlog_rx : NULL);
}
