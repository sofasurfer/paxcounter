#include "globals.h"
#include "devicelog.h"
#include "bleaddr.h"

// Linker hook: build flag -Wl,--wrap=_Z7mac_addPh11snifftype_t redirects all
// calls of libpax' mac_add() (from wifiscan.cpp / blescan.cpp) to the wrapper
// below. We record the full MAC and then call the original, so libpax'
// counting stays unchanged. Note: libpax itself only counts locally
// administered (randomized) MACs; we record all unicast MACs.

extern "C" int __real__Z7mac_addPh11snifftype_t(uint8_t *paddr, int type);

static_assert((HTTP_DEVICE_TABLE & (HTTP_DEVICE_TABLE - 1)) == 0,
              "HTTP_DEVICE_TABLE must be a power of 2");

#if defined(HAS_HTTP) && defined(HTTP_DEVICES)

#define MAX_FILL (HTTP_DEVICE_TABLE * 3 / 4) // keep probing chains short

static DeviceEntry_t tables[2][HTTP_DEVICE_TABLE];
static int fill[2] = {0, 0};
static uint32_t start_ms[2] = {0, 0}; // millis() when table became active
static volatile int active = 0; // table the sniffers write into
static int pending = -1;        // table taken for upload, -1 = none
static uint32_t batch_no = 0;
static uint32_t dropped = 0;
static portMUX_TYPE devlog_mux = portMUX_INITIALIZER_UNLOCKED;

static void devicelog_add(const uint8_t *mac, uint8_t type, uint8_t kind) {
  // FNV-1a over MAC + type
  uint32_t h = 2166136261u;
  for (int i = 0; i < 6; i++)
    h = (h ^ mac[i]) * 16777619u;
  h = (h ^ type) * 16777619u;

  const uint32_t now = millis();

  portENTER_CRITICAL(&devlog_mux);
  DeviceEntry_t *t = tables[active];
  uint32_t rel = (now - start_ms[active]) / 1000;
  const uint16_t sec = rel > UINT16_MAX ? UINT16_MAX : rel;
  for (uint32_t i = 0; i < HTTP_DEVICE_TABLE; i++) {
    DeviceEntry_t *e = &t[(h + i) & (HTTP_DEVICE_TABLE - 1)];
    if (!e->used) {
      if (fill[active] >= MAX_FILL) {
        dropped++;
        break;
      }
      memcpy(e->mac, mac, 6);
      e->type = type;
      e->kind = kind;
      e->used = 1;
      e->packets = 1;
      e->first_s = e->last_s = sec;
      fill[active]++;
      break;
    }
    if (e->type == type && memcmp(e->mac, mac, 6) == 0) {
      if (kind)
        e->kind = kind;
      if (e->packets < UINT16_MAX)
        e->packets++;
      e->last_s = sec;
      break;
    }
  }
  portEXIT_CRITICAL(&devlog_mux);
}

int devicelog_take(DeviceEntry_t **table, uint32_t *batch,
                   uint32_t *start) {
  if (pending < 0) {
    const uint32_t now = millis();
    portENTER_CRITICAL(&devlog_mux);
    pending = active;
    active ^= 1; // other table is empty (cleared by devicelog_done)
    start_ms[active] = now;
    portEXIT_CRITICAL(&devlog_mux);
    batch_no++;
  }
  *table = tables[pending];
  *batch = batch_no;
  *start = start_ms[pending];
  return fill[pending];
}

void devicelog_done(void) {
  if (pending < 0)
    return;
  memset(tables[pending], 0, sizeof(tables[pending]));
  fill[pending] = 0;
  pending = -1;
}

uint32_t devicelog_dropped(void) { return dropped; }

#endif

extern "C" int __wrap__Z7mac_addPh11snifftype_t(uint8_t *paddr, int type) {
#if defined(HAS_HTTP) && defined(HTTP_DEVICES)
  if (!(paddr[0] & 0x01)) // skip multicast / broadcast addresses
    devicelog_add(paddr, (uint8_t)type, type == 1 ? bleaddr_kind(paddr) : 0);
#endif
  return __real__Z7mac_addPh11snifftype_t(paddr, type);
}
