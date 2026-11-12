#include "globals.h"
#include "bleaddr.h"
#include <esp_bt.h>

// Linker hook: build flag -Wl,--wrap=esp_vhci_host_register_callback
// redirects libpax' call (blescan.cpp) to the wrapper below, which registers
// our own receive callback in front of libpax' one.

extern "C" esp_err_t
__real_esp_vhci_host_register_callback(const esp_vhci_host_callback_t *cb);

static const esp_vhci_host_callback_t *libpax_vhci = NULL;

#if defined(HAS_HTTP) && defined(HTTP_DEVICES)

// direct mapped cache address -> kind. libpax evaluates the reports shortly
// after we saw them (via its queue), so a small cache is enough; on a
// collision the older entry is simply overwritten.
#define CACHE_SIZE 256 // power of 2
typedef struct {
  uint8_t mac[6];
  uint8_t kind;
} AddrKind_t;
static AddrKind_t cache[CACHE_SIZE];
static portMUX_TYPE bleaddr_mux = portMUX_INITIALIZER_UNLOCKED;

static inline uint32_t slot(const uint8_t *mac) {
  uint32_t h = 2166136261u; // FNV-1a
  for (int i = 0; i < 6; i++)
    h = (h ^ mac[i]) * 16777619u;
  return h & (CACHE_SIZE - 1);
}

uint8_t bleaddr_kind(const uint8_t *mac) {
  uint8_t kind = 0;
  AddrKind_t *e = &cache[slot(mac)];
  portENTER_CRITICAL(&bleaddr_mux);
  if (e->kind && memcmp(e->mac, mac, 6) == 0)
    kind = e->kind;
  portEXIT_CRITICAL(&bleaddr_mux);
  return kind;
}

// HCI event: [0] 0x04 packet type, [1] 0x3E LE meta, [2] length,
// [3] 0x02 adv report, [4] num reports, then num x event type,
// num x address type, num x 6 address bytes (little endian), ...
// (Bluetooth Core Spec v5.0, Vol 2, Part E, 7.7.65.2)
static void bleaddr_parse(const uint8_t *d, uint16_t len) {
  if (len < 5 || d[0] != 0x04 || d[1] != 0x3E || d[3] != 0x02)
    return;
  const uint8_t n = d[4];
  if (n == 0 || n > 32 || 5 + 8 * (uint32_t)n > len)
    return;
  const uint8_t *types = d + 5 + n;
  const uint8_t *addrs = d + 5 + 2 * n;
  for (int i = 0; i < n; i++) {
    uint8_t mac[6];
    for (int j = 0; j < 6; j++) // same byte order as libpax
      mac[5 - j] = addrs[6 * i + j];
    uint8_t kind;
    switch (types[i]) {
    case 0x00: // public device address
    case 0x02: // public identity address (resolved by controller)
      kind = BLEADDR_PUBLIC;
      break;
    case 0x03: // random static identity address
      kind = BLEADDR_STATIC;
      break;
    case 0x01: // random: subtype is in the two most significant bits
      switch (mac[0] >> 6) {
      case 3:
        kind = BLEADDR_STATIC;
        break;
      case 1:
        kind = BLEADDR_RESOLVABLE;
        break;
      case 0:
        kind = BLEADDR_NONRESOLV;
        break;
      default:
        continue; // reserved
      }
      break;
    default:
      continue;
    }
    AddrKind_t *e = &cache[slot(mac)];
    portENTER_CRITICAL(&bleaddr_mux);
    memcpy(e->mac, mac, 6);
    e->kind = kind;
    portEXIT_CRITICAL(&bleaddr_mux);
  }
}

#else

uint8_t bleaddr_kind(const uint8_t *mac) { return 0; }

#endif

static int bleaddr_recv(uint8_t *data, uint16_t len) {
#if defined(HAS_HTTP) && defined(HTTP_DEVICES)
  bleaddr_parse(data, len);
#endif
  return libpax_vhci && libpax_vhci->notify_host_recv
             ? libpax_vhci->notify_host_recv(data, len)
             : 0;
}

static void bleaddr_send_available(void) {
  if (libpax_vhci && libpax_vhci->notify_host_send_available)
    libpax_vhci->notify_host_send_available();
}

static const esp_vhci_host_callback_t bleaddr_vhci = {
    bleaddr_send_available, bleaddr_recv};

extern "C" esp_err_t
__wrap_esp_vhci_host_register_callback(const esp_vhci_host_callback_t *cb) {
  libpax_vhci = cb;
  return __real_esp_vhci_host_register_callback(cb ? &bleaddr_vhci : NULL);
}
