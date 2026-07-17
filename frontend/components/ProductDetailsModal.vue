<template>
  <Teleport to="body">
    <div v-if="modelValue" class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/55 p-0 backdrop-blur-sm md:items-center md:p-6" @click.self="emit('close')">
      <div class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-t-[28px] bg-white shadow-[0_32px_120px_rgba(15,23,42,0.28)] md:rounded-[32px]">
        <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4 md:px-6">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">
              {{ product?.chain.name || summary?.chain.name }}
            </p>
            <h3 class="mt-2 text-2xl font-semibold text-slate-950">
              {{ summary?.name }}
            </h3>
            <p v-if="summary?.category?.name" class="mt-2 text-sm text-slate-500">
              {{ summary.category.name }}
            </p>
          </div>

          <button class="focus-ring inline-flex h-11 w-11 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:border-slate-300 hover:text-slate-900" type="button" @click="emit('close')">
            x
          </button>
        </div>

        <div class="grid min-h-0 gap-0 overflow-hidden md:grid-cols-[minmax(280px,340px)_minmax(0,1fr)]">
          <div class="border-b border-slate-100 bg-slate-50 p-5 md:border-b-0 md:border-r md:p-6">
            <div class="flex min-h-[240px] items-center justify-center rounded-[24px] bg-white p-6">
              <img
                v-if="summary?.image_url"
                :src="summary.image_url"
                :alt="summary.name"
                class="max-h-[240px] max-w-full object-contain"
              >
              <div v-else class="flex h-full min-h-[240px] w-full items-center justify-center rounded-[20px] border border-dashed border-slate-300 bg-slate-50 text-sm text-slate-500">
                Нет фото
              </div>
            </div>

            <div v-if="summary" class="mt-4 grid gap-3">
              <div class="rounded-[20px] bg-slate-950 px-4 py-4 text-white">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">Лучшая цена</p>
                <div class="mt-2 flex flex-wrap items-end gap-2">
                  <strong class="text-3xl font-semibold">{{ formatMoney(summary.best_price) }}</strong>
                  <span v-if="summary.old_price" class="text-sm text-slate-400 line-through">{{ formatMoney(summary.old_price) }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-300">
                  Скидка -{{ formatPercent(summary.discount_percent) }}% · выгода {{ formatMoney(summary.profit) }}
                </p>
              </div>

              <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-1">
                <div class="rounded-[20px] border border-slate-200 bg-white px-4 py-3">
                  <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Магазинов</p>
                  <p class="mt-2 text-2xl font-semibold text-slate-950">{{ summary.stores_count }}</p>
                </div>
                <div class="rounded-[20px] border border-slate-200 bg-white px-4 py-3">
                  <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Цена за единицу</p>
                  <p class="mt-2 text-2xl font-semibold text-slate-950">{{ formatMoney(summary.unit_price) }}</p>
                </div>
              </div>

              <div v-if="summary.levels?.length" class="rounded-[20px] border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-950">
                <p class="font-semibold uppercase tracking-[0.16em] text-emerald-700">Скидка от количества</p>
                <div class="mt-3 space-y-2">
                  <div v-for="level in summary.levels" :key="level.count" class="rounded-2xl bg-white/70 px-3 py-2">
                    От {{ level.count }} шт. · {{ formatMoney(level.price) }} · -{{ formatPercent(level.discount_percent) }}%
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="min-h-0 overflow-y-auto p-5 md:p-6">
            <div v-if="loading" class="space-y-3">
              <div v-for="item in 4" :key="item" class="h-24 animate-pulse rounded-[22px] border border-slate-200 bg-slate-50" />
            </div>

            <div v-else-if="errorMessage" class="rounded-[22px] border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              {{ errorMessage }}
            </div>

            <div v-else>
              <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Магазины по товару</p>
                <h4 class="mt-2 text-2xl font-semibold text-slate-950">
                  {{ offers.length }} {{ storeWord(offers.length) }}
                </h4>
              </div>

              <div v-if="offers.length" class="space-y-3">
                <article v-for="offer in offers" :key="`${offer.store_id}-${offer.external_id}`" class="rounded-[24px] border border-slate-200 bg-white p-4 shadow-sm">
                  <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div class="min-w-0">
                      <p class="text-base font-semibold text-slate-950">
                        {{ offer.address || `Магазин ${offer.external_id}` }}
                      </p>
                      <p class="mt-1 text-sm text-slate-500">
                        {{ chainName(offer.chain_code) }} · ID {{ offer.external_id }}
                      </p>
                    </div>

                    <div class="rounded-[18px] bg-slate-950 px-4 py-3 text-white">
                      <p class="text-xs uppercase tracking-[0.18em] text-slate-300">Цена</p>
                      <p class="mt-1 text-2xl font-semibold">{{ formatMoney(offer.price) }}</p>
                    </div>
                  </div>

                  <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-[18px] bg-slate-50 px-3 py-3">
                      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Старая цена</p>
                      <p class="mt-1 text-sm font-semibold text-slate-900">{{ formatMoney(offer.old_price) }}</p>
                    </div>
                    <div class="rounded-[18px] bg-slate-50 px-3 py-3">
                      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Скидка</p>
                      <p class="mt-1 text-sm font-semibold text-slate-900">-{{ formatPercent(offer.discount_percent) }}%</p>
                    </div>
                    <div class="rounded-[18px] bg-slate-50 px-3 py-3">
                      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Выгода</p>
                      <p class="mt-1 text-sm font-semibold text-slate-900">{{ formatMoney(offer.profit) }}</p>
                    </div>
                    <div class="rounded-[18px] bg-slate-50 px-3 py-3">
                      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Остаток</p>
                      <p class="mt-1 text-sm font-semibold text-slate-900">{{ formatStock(offer.stock) }}</p>
                    </div>
                  </div>
                </article>
              </div>

              <div v-else class="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">
                Для этого товара нет доступных предложений в выбранном городе.
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import type { Chain, DiscountProduct, StoreOffer } from '~/types/api'

const props = defineProps<{
  modelValue: boolean
  summary: DiscountProduct | null
  product: {
    chain: Chain
    offers: StoreOffer[]
  } | null
  loading: boolean
  errorMessage: string
  chains: Chain[]
}>()

const emit = defineEmits<{
  close: []
}>()

const offers = computed(() => props.product?.offers ?? [])

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.modelValue) {
    emit('close')
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
})

function chainName(code: string) {
  return props.chains.find((chain) => chain.code === code)?.name || code
}

function formatMoney(value: number | null) {
  if (value === null || Number.isNaN(value)) return '-'
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(value)
}

function formatPercent(value: number) {
  return Math.round(value)
}

function formatStock(value: number | null) {
  if (value === null) return 'Нет данных'
  if (value <= 0) return 'Нет в наличии'
  return `${value} шт.`
}

function storeWord(count: number) {
  if (count % 10 === 1 && count % 100 !== 11) return 'магазин'
  if ([2, 3, 4].includes(count % 10) && ![12, 13, 14].includes(count % 100)) return 'магазина'
  return 'магазинов'
}
</script>
