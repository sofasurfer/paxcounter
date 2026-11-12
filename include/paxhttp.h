#ifndef _PAXHTTP_H
#define _PAXHTTP_H

// Uploads pax counts via HTTP(S) POST to a REST endpoint over WiFi.
// Note: file is deliberately not named httpclient.h, which would clash with
// the Arduino <HTTPClient.h> on case-insensitive filesystems (macOS).

#ifdef HAS_HTTP

#include "globals.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <libpax_api.h>

#ifndef HTTP_WIFI_TIMEOUT
#define HTTP_WIFI_TIMEOUT 15 // seconds to wait for WiFi association
#endif
#ifndef HTTP_TIMEOUT
#define HTTP_TIMEOUT 10 // seconds to wait for HTTP response
#endif
#ifndef HTTP_RETRYSEC
#define HTTP_RETRYSEC 60 // wait before retrying after a failed upload
#endif
#ifndef HTTP_QUEUE_SIZE
#define HTTP_QUEUE_SIZE 120 // buffered measurements while offline
#endif
#ifndef HTTP_DEVICE_CHUNK
#define HTTP_DEVICE_CHUNK 40 // max. devices per POST request
#endif
#ifndef HTTP_BATCH_SIZE
#define HTTP_BATCH_SIZE 20 // max. measurements per POST request
#endif

typedef struct {
  uint32_t seq;          // running number since boot (dedup key on server)
  uint32_t timestamp_ms; // millis() when measurement was taken
  uint32_t pax;
  uint32_t wifi;
  uint32_t ble;
} HttpCount_t;

extern TaskHandle_t httpTask;

esp_err_t http_init(void);
void http_deinit(void);
void http_enqueuecount(const struct count_payload_t *count);
uint32_t http_queuewaiting(void);
void http_queuereset(void);

#endif // HAS_HTTP

#endif // _PAXHTTP_H
