#ifndef _DEVICELOG_H
#define _DEVICELOG_H

// Per-device logging: collects every distinct MAC address seen by the libpax
// WiFi and BLE sniffers (hooked via linker --wrap on libpax' mac_add()), so
// that individual devices can be uploaded by paxhttp.cpp.

#include <stdint.h>

#ifndef HTTP_DEVICE_TABLE
#define HTTP_DEVICE_TABLE 256 // slots per table (x2), must be power of 2
#endif

// kept compact (16 bytes), RAM is tight with BLE + WiFi + TLS
typedef struct {
  uint8_t mac[6];
  uint8_t type;     // snifftype_t: 0 = WiFi, 1 = BLE
  uint8_t used;
  uint8_t kind;     // BLE address kind (see bleaddr.h), 0 = unknown / WiFi
  uint16_t packets; // frames/advertisements seen (saturating)
  uint16_t first_s; // seconds since table start, first sighting
  uint16_t last_s;  // seconds since table start, last sighting
} DeviceEntry_t;

// hands out the table to upload (flips double buffer if no upload pending);
// returns number of devices in it, *table and *batch are set
// *start_ms receives millis() at table start (base for first_s/last_s)
int devicelog_take(DeviceEntry_t **table, uint32_t *batch, uint32_t *start_ms);
// marks the taken table as uploaded and clears it
void devicelog_done(void);
// devices dropped because table was full
uint32_t devicelog_dropped(void);

#endif
