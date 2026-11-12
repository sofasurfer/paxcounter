#ifndef _NETLOG_H
#define _NETLOG_H

// WiFi network logging: records the name (SSID) of every access point whose
// beacon frames the libpax WiFi sniffer receives, so that paxhttp.cpp can
// upload them. Hooked in via linker --wrap on esp_wifi_set_promiscuous_rx_cb,
// libpax' own packet handler still sees every frame unchanged.
//
// Only beacons (sent by access points) are evaluated. Probe requests, which
// contain the networks a phone is looking for, are deliberately ignored.

#include <stdint.h>

#ifndef HTTP_NET_TABLE
#define HTTP_NET_TABLE 64 // max. access points per upload interval
#endif
#ifndef HTTP_NET_INTERVAL
#define HTTP_NET_INTERVAL 600 // seconds between network list uploads
#endif

typedef struct {
  uint8_t bssid[6];
  uint8_t used;
  uint8_t channel;
  int8_t rssi_max;  // strongest beacon in interval [dBm]
  int8_t rssi_last; // last beacon [dBm]
  uint8_t ssid_len; // 0 = hidden network
  uint8_t ssid[32]; // raw bytes, not terminated, may be any encoding
  uint16_t beacons; // saturating
  uint32_t last_ms; // millis() of last beacon
} NetEntry_t;

// iterates the networks seen since the last netlog_clear(): copies the next
// one at or after index i into *out and returns the index to continue with,
// or -1 if there are no more
int netlog_next(int i, NetEntry_t *out);
// forgets all networks (call after a successful upload)
void netlog_clear(void);
// networks dropped because the table was full
uint32_t netlog_dropped(void);

#endif
