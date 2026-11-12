#ifndef _BLEADDR_H
#define _BLEADDR_H

// BLE address type tracking: libpax only passes the 6 address bytes of a BLE
// advertiser to mac_add(), not whether the address is public or random. We
// hook libpax' HCI receive callback (linker --wrap on
// esp_vhci_host_register_callback), read the address type from every LE
// advertising report and remember it in a small cache, which devicelog.cpp
// queries. libpax still receives every HCI packet unchanged.

#include <stdint.h>

// address kinds, as uploaded to the server
#define BLEADDR_PUBLIC 'p'      // public (IEEE registered), fixed
#define BLEADDR_STATIC 's'      // random static, fixed until power cycle
#define BLEADDR_RESOLVABLE 'r'  // resolvable private, rotates (~15 min)
#define BLEADDR_NONRESOLV 'n'   // non-resolvable private, rotates

// kind of a BLE address (byte order as passed to mac_add), 0 if unknown
uint8_t bleaddr_kind(const uint8_t *mac);

#endif
