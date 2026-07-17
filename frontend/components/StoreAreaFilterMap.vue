<template>
  <div class="rounded-[24px] border border-slate-200 bg-slate-50 p-4">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Область на карте</p>
        <p class="mt-2 text-sm leading-6 text-slate-600">
          Показываем {{ storesWithCoords.length }} {{ storeWord(storesWithCoords.length) }} на карте.
          <span v-if="hasAppliedRegion"> Внутри выделенной области: {{ storesInsideRegion }}.</span>
        </p>
      </div>

      <div class="flex flex-wrap gap-2">
        <button
          class="focus-ring inline-flex min-h-11 items-center justify-center rounded-full border px-4 py-2 text-sm font-semibold transition"
          :class="drawMode ? 'border-brand bg-brand text-white' : 'border-slate-300 bg-white text-slate-900 hover:border-brand hover:text-brand'"
          type="button"
          @click="toggleDrawMode"
        >
          {{ drawMode ? 'Отменить рисование' : 'Нарисовать область' }}
        </button>
        <button
          class="focus-ring inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 transition hover:border-brand hover:text-brand disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="!drawMode || currentPoints.length < 3"
          type="button"
          @click="finishPolygon"
        >
          Применить область
        </button>
        <button
          class="focus-ring inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 transition hover:border-brand hover:text-brand disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="!hasAppliedRegion"
          type="button"
          @click="clearPolygon"
        >
          Сбросить
        </button>
      </div>
    </div>

    <div class="mt-4 rounded-[22px] border border-slate-200 bg-white p-3">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-slate-600">
          <span v-if="drawMode">Кликайте по карте, чтобы поставить точки. Затем нажмите «Применить область».</span>
          <span v-else-if="hasAppliedRegion">Фильтр по карте активен. Выдача ограничена выбранной областью.</span>
          <span v-else>Можно выделить область и отфильтровать товары по магазинам внутри нее.</span>
        </p>
        <p v-if="mapError" class="text-sm font-semibold text-amber-700">{{ mapError }}</p>
      </div>

      <div v-if="loading" class="h-[320px] animate-pulse rounded-[18px] bg-slate-100 md:h-[380px]" />
      <div v-else ref="mapContainer" class="h-[320px] overflow-hidden rounded-[18px] border border-slate-200 bg-slate-100 md:h-[380px]" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import type { Store } from '~/types/api'

type PolygonPoint = [number, number]
type YMap = any

const props = defineProps<{
  modelValue: PolygonPoint[]
  stores: Store[]
  loading: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: PolygonPoint[]]
}>()

const config = useRuntimeConfig()
const mapContainer = ref<HTMLDivElement | null>(null)
const drawMode = ref(false)
const currentPoints = ref<PolygonPoint[]>([])
const mapError = ref('')

const storesWithCoords = computed(() => props.stores.filter((store) => store.latitude !== null && store.longitude !== null))
const hasAppliedRegion = computed(() => props.modelValue.length >= 3)
const storesInsideRegion = computed(() => {
  if (!hasAppliedRegion.value) {
    return storesWithCoords.value.length
  }

  return storesWithCoords.value.filter((store) => isPointInPolygon(store.latitude as number, store.longitude as number, props.modelValue)).length
})

let yandexMap: YMap = null
let yandexCollection: YMap = null
let polygonObject: YMap = null
let drawLine: YMap = null

watch(
  () => props.modelValue,
  (value) => {
    if (!drawMode.value) {
      currentPoints.value = [...value]
      redrawPolygon()
      redrawMarkers()
    }
  },
  { deep: true, immediate: true }
)

watch(
  () => props.stores,
  async () => {
    await nextTick()
    await initMapIfPossible()
    redrawMarkers()
  },
  { deep: true }
)

watch(
  () => props.loading,
  async (loading) => {
    if (!loading) {
      await nextTick()
      await initMapIfPossible()
    }
  },
  { immediate: true }
)

watch(currentPoints, () => {
  redrawPolygon()
  redrawMarkers()
}, { deep: true })

onBeforeUnmount(() => {
  if (yandexMap) {
    yandexMap.destroy()
    yandexMap = null
  }
})

function toggleDrawMode() {
  if (drawMode.value) {
    drawMode.value = false
    currentPoints.value = [...props.modelValue]
    return
  }

  drawMode.value = true
  currentPoints.value = []
}

function finishPolygon() {
  if (currentPoints.value.length < 3) {
    return
  }

  drawMode.value = false
  emit('update:modelValue', [...currentPoints.value])
}

function clearPolygon() {
  drawMode.value = false
  currentPoints.value = []
  emit('update:modelValue', [])
}

async function loadYandexMaps() {
  if (typeof window === 'undefined') {
    throw new Error('Yandex Maps can only be loaded on the client')
  }

  const globalAny = window as any
  if (globalAny.ymaps) return globalAny.ymaps

  const params = new URLSearchParams({ lang: 'ru_RU' })
  const apiKey = String(config.public.yandexMapsApiKey || '')
  if (apiKey) {
    params.set('apikey', apiKey)
  }

  await new Promise<void>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = `https://api-maps.yandex.ru/2.1/?${params.toString()}`
    script.async = true
    script.onload = () => resolve()
    script.onerror = () => reject(new Error('Yandex Maps script failed'))
    document.body.appendChild(script)
  })

  if (!globalAny.ymaps) {
    throw new Error('Yandex Maps did not initialize')
  }

  return globalAny.ymaps
}

async function initMapIfPossible() {
  if (!mapContainer.value || props.loading) {
    return
  }

  try {
    mapError.value = ''
    const ymaps = await loadYandexMaps()
    await new Promise<void>((resolve) => ymaps.ready(resolve))

    if (!yandexMap) {
      yandexMap = new ymaps.Map(mapContainer.value, {
        center: mapCenter(),
        zoom: 11,
        controls: ['zoomControl', 'fullscreenControl']
      })

      yandexCollection = new ymaps.GeoObjectCollection()
      yandexMap.geoObjects.add(yandexCollection)
      yandexMap.events.add('click', onMapClick)
      yandexMap.container.fitToViewport()
    } else {
      yandexMap.container.fitToViewport()
    }

    redrawPolygon()
    redrawMarkers()
    fitMapToStores()
  } catch {
    mapError.value = 'Карта не загрузилась.'
  }
}

function onMapClick(event: any) {
  if (!drawMode.value) {
    return
  }

  const coords = event.get('coords')
  if (!Array.isArray(coords) || coords.length < 2) {
    return
  }

  currentPoints.value = [...currentPoints.value, [Number(coords[0]), Number(coords[1])]]
}

function redrawMarkers() {
  if (!yandexCollection || !(window as any).ymaps) {
    return
  }

  const ymaps = (window as any).ymaps
  yandexCollection.removeAll()

  for (const store of storesWithCoords.value) {
    const activePolygon = currentPoints.value.length >= 3 ? currentPoints.value : props.modelValue
    const insideRegion = activePolygon.length < 3 || isPointInPolygon(store.latitude as number, store.longitude as number, activePolygon)

    yandexCollection.add(new ymaps.Placemark([store.latitude, store.longitude], {
      balloonContentHeader: store.name || store.chain.name,
      balloonContentBody: store.address || '',
      hintContent: store.address || store.name || store.chain.name
    }, {
      preset: insideRegion ? 'islands#redShoppingIcon' : 'islands#grayCircleIcon'
    }))
  }
}

function redrawPolygon() {
  if (!yandexMap || !(window as any).ymaps) {
    return
  }

  const ymaps = (window as any).ymaps

  if (polygonObject) {
    yandexMap.geoObjects.remove(polygonObject)
    polygonObject = null
  }

  if (drawLine) {
    yandexMap.geoObjects.remove(drawLine)
    drawLine = null
  }

  if (currentPoints.value.length >= 3) {
    polygonObject = new ymaps.Polygon([currentPoints.value], {}, {
      fillColor: 'rgba(21, 112, 239, 0.18)',
      strokeColor: '#1570ef',
      strokeWidth: 3
    })
    yandexMap.geoObjects.add(polygonObject)
    return
  }

  if (drawMode.value && currentPoints.value.length >= 2) {
    drawLine = new ymaps.Polyline(currentPoints.value, {}, {
      strokeColor: '#1570ef',
      strokeWidth: 3,
      strokeStyle: 'dash'
    })
    yandexMap.geoObjects.add(drawLine)
  }
}

function fitMapToStores() {
  if (!yandexMap || !yandexCollection || !storesWithCoords.value.length) {
    return
  }

  const bounds = yandexCollection.getBounds?.()
  if (bounds) {
    yandexMap.setBounds(bounds, { checkZoomRange: true, zoomMargin: 32 })
  }
}

function mapCenter(): PolygonPoint {
  const first = storesWithCoords.value[0]
  if (first?.latitude !== null && first?.longitude !== null) {
    return [first.latitude, first.longitude]
  }

  return [55.751244, 37.618423]
}

function isPointInPolygon(latitude: number, longitude: number, polygon: PolygonPoint[]) {
  let inside = false

  for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
    const yi = polygon[i][0]
    const xi = polygon[i][1]
    const yj = polygon[j][0]
    const xj = polygon[j][1]

    const intersects = ((yi > latitude) !== (yj > latitude))
      && (longitude < ((xj - xi) * (latitude - yi)) / ((yj - yi) || Number.EPSILON) + xi)

    if (intersects) {
      inside = !inside
    }
  }

  return inside
}

function storeWord(count: number) {
  if (count % 10 === 1 && count % 100 !== 11) return 'магазин'
  if ([2, 3, 4].includes(count % 10) && ![12, 13, 14].includes(count % 100)) return 'магазина'
  return 'магазинов'
}
</script>
