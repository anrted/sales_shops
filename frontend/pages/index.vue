<template>
  <main class="page-shell min-h-screen text-ink">
    <section class="border-b border-white/60 bg-white/70 backdrop-blur">
      <div class="mx-auto flex max-w-[1600px] flex-col gap-4 px-4 py-4 md:py-5">
        <div>
          <div class="hero-panel overflow-hidden rounded-[26px] border border-white/70 px-5 py-5 shadow-[0_18px_60px_rgba(15,23,42,0.08)] md:px-6 md:py-6">
            <div class="relative z-10 flex flex-col gap-3">
              <div class="flex justify-end">
                <NuxtLink class="focus-ring inline-flex items-center justify-center rounded-full border border-white/80 bg-white/90 px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm transition hover:-translate-y-0.5 hover:border-brand hover:text-brand" to="/admin">
                  Админка
                </NuxtLink>
              </div>

              <div class="grid gap-2 md:grid-cols-2">
                <div class="stat-card">
                  <span class="stat-card__label">Товаров в выдаче</span>
                  <strong class="stat-card__value">{{ formattedTotal }}</strong>
                </div>
                <div class="stat-card">
                  <span class="stat-card__label">Текущий город</span>
                  <strong class="stat-card__value text-xl md:text-2xl">{{ selectedCityName }}</strong>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="rounded-[24px] border border-white/70 bg-white/80 p-3 shadow-[0_16px_48px_rgba(15,23,42,0.08)] backdrop-blur">
          <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,1.25fr)_repeat(3,minmax(0,0.8fr))]">
            <label class="filter-field">
              <span class="filter-field__label">Город</span>
              <select v-model="filters.cityId" class="filter-field__control">
                <option :value="0">Выберите город</option>
                <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
              </select>
            </label>

            <label class="filter-field">
              <span class="filter-field__label">Поиск</span>
              <input
                v-model.trim="filters.q"
                class="filter-field__control"
                placeholder="Например, кофе, сыр, шампунь"
                type="search"
              >
            </label>

            <label class="filter-field">
              <span class="filter-field__label">Сеть</span>
              <select v-model="filters.chain" class="filter-field__control">
                <option value="">Все сети</option>
                <option v-for="chain in chains" :key="chain.code" :value="chain.code">{{ chain.name }}</option>
              </select>
            </label>

            <label class="filter-field">
              <span class="filter-field__label">Категория</span>
              <select v-model="filters.category" class="filter-field__control">
                <option :value="0">Все категории</option>
                <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
              </select>
            </label>

            <label class="filter-field">
              <span class="filter-field__label">Сортировка</span>
              <select v-model="filters.sort" class="filter-field__control">
                <option value="discount">Макс. скидка</option>
                <option value="profit">Макс. выгода</option>
                <option value="price">Мин. цена</option>
                <option value="unit_price">Цена за единицу</option>
              </select>
            </label>
          </div>

          <div class="mt-3">
            <button
              class="focus-ring inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 transition hover:border-brand hover:text-brand"
              type="button"
              @click="areaMapOpen = !areaMapOpen"
            >
              {{ areaMapOpen ? 'Скрыть фильтр по карте' : 'Фильтр по карте' }}
            </button>
          </div>

          <div v-if="areaMapOpen" class="mt-3">
            <StoreAreaFilterMap
              v-model="areaPolygon"
              :stores="mapStores"
              :loading="mapStoresPending"
            />
          </div>

          <div class="mt-3 flex flex-col gap-3 rounded-[22px] bg-slate-950 px-4 py-4 text-white md:flex-row md:items-center md:justify-between">
            <div class="flex flex-wrap items-center gap-2">
              <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-200">Активные фильтры</span>
              <span v-for="chip in filterChips" :key="chip" class="rounded-full bg-white px-3 py-1 text-sm font-medium text-slate-900">
                {{ chip }}
              </span>
            </div>

            <button
              class="focus-ring inline-flex min-h-11 items-center justify-center rounded-full bg-white px-5 py-2 text-sm font-semibold text-slate-950 transition hover:-translate-y-0.5 hover:bg-cyan-50 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="isBusy"
              @click="refreshProducts"
            >
              {{ refreshing ? 'Обновляем…' : 'Обновить выдачу' }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <section class="mx-auto max-w-[1600px] px-4 py-6 md:py-8">
      <div class="mb-5 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
        <div>
          <p class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Результаты</p>
          <h2 class="font-display mt-2 text-3xl font-semibold text-slate-950 md:text-4xl">
            {{ resultsHeadline }}
          </h2>
          <p class="mt-2 text-sm leading-6 text-slate-600">
            {{ resultsCaption }}
          </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <div class="summary-card">
            <span class="summary-card__label">Показано</span>
            <strong class="summary-card__value">{{ products.length }}</strong>
          </div>
          <div class="summary-card">
            <span class="summary-card__label">Доступно всего</span>
            <strong class="summary-card__value">{{ formattedTotal }}</strong>
          </div>
        </div>
      </div>

      <div v-if="errorMessage" class="mb-5 rounded-[22px] border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        {{ errorMessage }}
      </div>

      <div v-if="initialLoading" class="products-grid">
        <div v-for="item in 8" :key="item" class="h-[430px] min-w-0 animate-pulse rounded-[26px] border border-white/70 bg-white/80 shadow-sm" />
      </div>

      <div v-else-if="products.length" class="products-grid">
        <ProductCard v-for="product in products" :key="product.id" :product="product" @open="openProductModal" />
      </div>

      <div v-else class="rounded-[28px] border border-dashed border-slate-300 bg-white/80 p-10 text-center shadow-sm backdrop-blur">
        <p class="font-display text-2xl font-semibold text-slate-950">Ничего не найдено</p>
        <p class="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-600">
          Выберите город или измените фильтры. Если товары должны быть, попробуйте сбросить поиск, карту или переключить сортировку.
        </p>
      </div>

      <div v-if="products.length && loadingMore" class="mt-5 products-grid">
        <div v-for="item in 4" :key="`more-${item}`" class="h-[430px] min-w-0 animate-pulse rounded-[26px] border border-white/70 bg-white/80 shadow-sm" />
      </div>

      <div v-if="hasMore" class="mt-8 flex justify-center">
        <button
          class="focus-ring inline-flex min-h-14 min-w-[220px] items-center justify-center rounded-full bg-slate-950 px-6 py-3 text-base font-semibold text-white shadow-[0_18px_40px_rgba(15,23,42,0.18)] transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="loadingMore || refreshing || initialLoading"
          @click="loadMore"
        >
          {{ loadingMore ? 'Подгружаем товары…' : 'Загрузить еще' }}
        </button>
      </div>
    </section>

    <ProductDetailsModal
      :model-value="productModalOpen"
      :summary="selectedProductSummary"
      :product="selectedProductDetails"
      :loading="productModalLoading"
      :error-message="productModalError"
      :chains="chains"
      @close="closeProductModal"
    />
  </main>
</template>

<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import type {
  Category,
  Chain,
  City,
  DiscountProduct,
  DiscountResponse,
  ListResponse,
  ProductDetailsResponse,
  Store,
  StoreOffer
} from '~/types/api'

type PolygonPoint = [number, number]

const api = useApi()
const route = useRoute()
const router = useRouter()
const selectedCityId = useCookie<number>('discounts_city_id', {
  default: () => 0,
  maxAge: 60 * 60 * 24 * 365,
  sameSite: 'lax'
})

const sortLabels: Record<string, string> = {
  discount: 'максимальной скидке',
  profit: 'максимальной выгоде',
  price: 'минимальной цене',
  unit_price: 'цене за единицу'
}

const filters = reactive({
  cityId: normalizeCityId(selectedCityId.value),
  q: '',
  chain: '',
  category: 0,
  sort: 'discount'
})

const products = ref<DiscountProduct[]>([])
const total = ref(0)
const offset = ref(0)
const limit = 48
const initialLoading = ref(true)
const refreshing = ref(false)
const loadingMore = ref(false)
const errorMessage = ref('')
const areaMapOpen = ref(false)
const areaPolygon = ref<PolygonPoint[]>([])
const productModalOpen = ref(false)
const productModalLoading = ref(false)
const productModalError = ref('')
const selectedProductSummary = ref<DiscountProduct | null>(null)
const selectedProductDetails = ref<{ chain: Chain, offers: StoreOffer[] } | null>(null)
const routeProductSyncInProgress = ref(false)

let refreshTimer: ReturnType<typeof setTimeout> | null = null

const { data: citiesData } = await useAsyncData('cities', () => api<ListResponse<City>>('/cities'))
const { data: chainsData } = await useAsyncData('chains', () => api<ListResponse<Chain>>('/chains'))
const { data: categoriesData, refresh: refreshCategories } = await useAsyncData('categories', () => api<ListResponse<Category>>('/categories', {
  query: { chain: filters.chain || undefined, top_only: 1 }
}))
const {
  data: mapStoresData,
  pending: mapStoresPending,
  refresh: refreshMapStores
} = await useAsyncData('filter-map-stores', () => api<ListResponse<Store>>('/stores', {
  query: {
    city_id: filters.cityId || undefined,
    chain: filters.chain || undefined
  }
}))

const cities = computed(() => citiesData.value?.items || [])
const chains = computed(() => chainsData.value?.items || [])
const categories = computed(() => categoriesData.value?.items || [])
const mapStores = computed(() => mapStoresData.value?.items || [])
const selectedCityName = computed(() => cities.value.find((city) => city.id === filters.cityId)?.name || 'Все города')
const selectedChainName = computed(() => chains.value.find((chain) => chain.code === filters.chain)?.name || 'Все сети')
const selectedCategoryName = computed(() => categories.value.find((category) => category.id === filters.category)?.name || 'Все категории')
const selectedSortLabel = computed(() => sortLabels[filters.sort] || 'скидке')
const formattedTotal = computed(() => new Intl.NumberFormat('ru-RU').format(total.value))
const isBusy = computed(() => initialLoading.value || refreshing.value || loadingMore.value)
const hasMore = computed(() => products.value.length < total.value)
const hasAreaFilter = computed(() => areaPolygon.value.length >= 3)
const resultsHeadline = computed(() => filters.q ? `Результаты для «${filters.q}»` : 'Актуальные скидки по магазинам')
const resultsCaption = computed(() => {
  const parts = [
    `Город: ${selectedCityName.value}`,
    `Сеть: ${selectedChainName.value}`,
    `Категория: ${selectedCategoryName.value}`,
    `Сортировка по ${selectedSortLabel.value}`
  ]

  if (hasAreaFilter.value) {
    parts.push('с учетом области на карте')
  }

  return `${parts.join('. ')}.`
})

const filterChips = computed(() => {
  const chips = [`Город: ${selectedCityName.value}`, `Сорт: ${selectedSortLabel.value}`]

  if (filters.chain) {
    chips.push(`Сеть: ${selectedChainName.value}`)
  }

  if (filters.category) {
    chips.push(`Категория: ${selectedCategoryName.value}`)
  }

  if (filters.q) {
    chips.push(`Поиск: ${filters.q}`)
  }

  if (hasAreaFilter.value) {
    chips.push('Область: на карте')
  }

  return chips
})

watch(
  () => [filters.cityId, filters.q, filters.chain, filters.category, filters.sort],
  () => {
    scheduleRefresh(filters.q ? 250 : 0)
  }
)

watch(
  () => [filters.cityId, filters.chain],
  async () => {
    areaPolygon.value = []
    await refreshMapStores()
  }
)

watch(areaPolygon, () => {
  scheduleRefresh(0)
}, { deep: true })

watch(() => filters.cityId, (cityId) => {
  selectedCityId.value = normalizeCityId(cityId)
})

watch(cities, (items) => {
  if (items.length && filters.cityId && !items.some((city) => city.id === filters.cityId)) {
    filters.cityId = 0
  }
}, { immediate: true })

watch(() => filters.chain, async () => {
  filters.category = 0
  await refreshCategories()
})

watch(productModalOpen, (isOpen) => {
  if (import.meta.client) {
    document.body.style.overflow = isOpen ? 'hidden' : ''
  }
})

watch(
  () => route.query.product,
  async (productQuery) => {
    if (routeProductSyncInProgress.value) {
      return
    }

    const productId = normalizeProductId(productQuery)

    if (!productId) {
      if (productModalOpen.value) {
        closeProductModal({ syncRoute: false })
      }
      return
    }

    if (selectedProductSummary.value?.id === productId && productModalOpen.value) {
      return
    }

    const existingProduct = products.value.find((product) => product.id === productId) || null
    await openProductModalById(productId, existingProduct, false)
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  if (refreshTimer) {
    clearTimeout(refreshTimer)
  }

  if (import.meta.client) {
    document.body.style.overflow = ''
  }
})

await fetchProducts()

async function refreshProducts() {
  offset.value = 0
  await fetchProducts()
}

async function loadMore() {
  if (!hasMore.value || loadingMore.value) {
    return
  }

  offset.value += limit
  await fetchProducts({ append: true })
}

async function openProductModal(product: DiscountProduct) {
  await openProductModalById(product.id, product, true)
}

function closeProductModal(options: { syncRoute?: boolean } = {}) {
  const syncRoute = options.syncRoute !== false
  productModalOpen.value = false
  productModalLoading.value = false

  if (syncRoute) {
    syncRouteProduct(null)
  }
}

function scheduleRefresh(delay = 0) {
  if (refreshTimer) {
    clearTimeout(refreshTimer)
  }

  refreshTimer = setTimeout(() => {
    offset.value = 0
    fetchProducts()
  }, delay)
}

async function fetchProducts(options: { append?: boolean } = {}) {
  const append = options.append === true

  errorMessage.value = ''

  if (append) {
    loadingMore.value = true
  } else if (products.value.length) {
    refreshing.value = true
  } else {
    initialLoading.value = true
  }

  try {
    const response = await api<DiscountResponse>('/discounts', {
      query: {
        city_id: filters.cityId || undefined,
        q: filters.q || undefined,
        chain: filters.chain || undefined,
        category: filters.category || undefined,
        sort: filters.sort,
        polygon: hasAreaFilter.value ? JSON.stringify(areaPolygon.value) : undefined,
        limit,
        offset: offset.value
      }
    })

    if (append) {
      const existingIds = new Set(products.value.map((product) => product.id))
      products.value = [...products.value, ...response.items.filter((product) => !existingIds.has(product.id))]
    } else {
      products.value = response.items
    }

    total.value = response.total
    offset.value = response.offset
  } catch (error) {
    if (append) {
      offset.value = Math.max(0, offset.value - limit)
    }

    errorMessage.value = error instanceof Error ? error.message : 'Не удалось загрузить товары. Попробуйте еще раз.'
  } finally {
    initialLoading.value = false
    refreshing.value = false
    loadingMore.value = false
  }
}

async function openProductModalById(productId: number, product: DiscountProduct | null, syncRoute: boolean) {
  if (syncRoute) {
    syncRouteProduct(productId)
  }

  if (product) {
    selectedProductSummary.value = product
    selectedProductDetails.value = {
      chain: product.chain,
      offers: product.stores
    }
  } else {
    selectedProductSummary.value = null
    selectedProductDetails.value = null
  }

  productModalError.value = ''
  productModalOpen.value = true
  productModalLoading.value = true

  try {
    const response = await api<ProductDetailsResponse>(`/products/${productId}`, {
      query: {
        city_id: filters.cityId || undefined
      }
    })

    selectedProductSummary.value = {
      id: response.item.id,
      name: response.item.name,
      image_url: response.item.image_url,
      chain: response.item.chain,
      category: response.item.category as Category | null,
      best_price: response.item.best_price,
      old_price: response.item.old_price,
      discount_percent: response.item.discount_percent,
      profit: response.item.profit,
      unit_price: response.item.unit_price,
      stores_count: response.item.stores_count,
      levels: response.item.levels,
      stores: response.item.offers
    }
    selectedProductDetails.value = {
      chain: response.item.chain,
      offers: response.item.offers
    }
  } catch (error) {
    productModalError.value = error instanceof Error ? error.message : 'Не удалось загрузить магазины по товару.'
  } finally {
    productModalLoading.value = false
  }
}

function syncRouteProduct(productId: number | null) {
  routeProductSyncInProgress.value = true

  const query = { ...route.query }
  if (productId) {
    query.product = String(productId)
  } else {
    delete query.product
  }

  router.replace({ query }).finally(() => {
    routeProductSyncInProgress.value = false
  })
}

function normalizeCityId(value: unknown) {
  const cityId = Number(value)
  return Number.isFinite(cityId) && cityId > 0 ? Math.trunc(cityId) : 0
}

function normalizeProductId(value: unknown) {
  const raw = Array.isArray(value) ? value[0] : value
  const productId = Number(raw)
  return Number.isFinite(productId) && productId > 0 ? Math.trunc(productId) : 0
}
</script>

<style scoped>
.products-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1rem;
  min-width: 0;
}

.products-grid > * {
  min-width: 0;
}

@media (min-width: 640px) {
  .products-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (min-width: 1024px) {
  .products-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (min-width: 1280px) {
  .products-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

.hero-panel {
  background:
    radial-gradient(circle at top left, rgba(34, 211, 238, 0.34), transparent 32%),
    radial-gradient(circle at 85% 20%, rgba(59, 130, 246, 0.22), transparent 28%),
    linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(243, 247, 255, 0.88));
}

.stat-card {
  @apply rounded-[20px] border border-white/70 bg-white/70 px-4 py-3 backdrop-blur;
}

.stat-card__label {
  @apply text-xs font-semibold uppercase tracking-[0.18em] text-slate-500;
}

.stat-card__value {
  @apply mt-2 block text-2xl font-semibold text-slate-950;
}

.filter-field {
  @apply flex min-w-0 flex-col gap-2 rounded-[20px] border border-slate-200 bg-white px-3 py-3;
}

.filter-field__label {
  @apply text-xs font-semibold uppercase tracking-[0.18em] text-slate-500;
}

.filter-field__control {
  @apply min-w-0 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-900 transition placeholder:text-slate-400 hover:border-brand/40 focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2;
}

.summary-card {
  @apply rounded-[22px] border border-white/70 bg-white/85 px-4 py-4 shadow-sm backdrop-blur;
}

.summary-card__label {
  @apply text-xs font-semibold uppercase tracking-[0.18em] text-slate-500;
}

.summary-card__value {
  @apply mt-3 block text-3xl font-semibold text-slate-950;
}
</style>
