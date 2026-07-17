<template>
  <article
    class="group grid h-full min-h-[460px] min-w-0 max-w-full cursor-pointer grid-rows-[200px_minmax(0,1fr)_auto] overflow-hidden rounded-[26px] border border-white/70 bg-white/90 shadow-[0_16px_50px_rgba(15,23,42,0.08)] transition duration-300 hover:-translate-y-1 hover:shadow-[0_28px_70px_rgba(15,23,42,0.14)]"
    role="button"
    tabindex="0"
    @click="emit('open', product)"
    @keydown.enter.prevent="emit('open', product)"
    @keydown.space.prevent="emit('open', product)"
  >
    <div class="relative min-w-0 overflow-hidden bg-[radial-gradient(circle_at_top,rgba(14,165,233,0.16),transparent_45%),linear-gradient(180deg,#f8fbff_0%,#eef5ff_100%)]">
      <div class="absolute inset-x-0 top-0 flex items-start justify-between gap-2 p-3">
        <span class="rounded-full bg-slate-950 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-white">
          {{ product.chain.name }}
        </span>
        <span v-if="product.discount_percent > 0" class="rounded-full bg-deal px-3 py-1 text-sm font-bold text-white shadow-sm">
          -{{ formatPercent(product.discount_percent) }}%
        </span>
      </div>

      <div class="flex h-full items-center justify-center px-5 py-8">
        <img
          v-if="product.image_url"
          :src="product.image_url"
          :alt="product.name"
          class="block max-h-full max-w-full object-contain transition duration-300 group-hover:scale-[1.04]"
          loading="lazy"
        >
        <div v-else class="flex h-full w-full items-center justify-center rounded-[22px] border border-dashed border-slate-300 bg-white/70 text-sm text-muted">
          Нет фото
        </div>
      </div>
    </div>

    <div class="flex min-h-0 min-w-0 flex-col gap-4 p-4">
      <div class="flex min-h-[28px] min-w-0 flex-wrap items-center justify-between gap-2">
        <span v-if="product.category" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
          {{ product.category.name }}
        </span>
        <span v-else class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">
          Без категории
        </span>

        <span class="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
          {{ storeCountLabel }}
        </span>
      </div>

      <h2 class="min-h-[5rem] min-w-0 text-base font-semibold leading-6 text-slate-950 [display:-webkit-box] [-webkit-box-orient:vertical] [-webkit-line-clamp:3] overflow-hidden">
        {{ product.name }}
      </h2>

      <div class="rounded-[22px] bg-slate-950 px-4 py-4 text-white">
        <div class="flex min-h-[34px] min-w-0 flex-wrap items-end gap-x-2 gap-y-1">
          <span class="min-w-0 break-words text-3xl font-semibold leading-none">{{ formatMoney(product.best_price) }}</span>
          <span v-if="product.old_price" class="min-w-0 break-words text-sm text-slate-400 line-through">{{ formatMoney(product.old_price) }}</span>
        </div>
        <p class="mt-2 text-sm text-slate-300">
          Выгода {{ formatMoney(product.profit) }}
          <span v-if="product.unit_price !== null"> · {{ formatMoney(product.unit_price) }} за ед.</span>
        </p>
      </div>

      <div v-if="product.levels?.length" class="rounded-[20px] border border-emerald-100 bg-emerald-50 p-3 text-xs text-emerald-950">
        <p class="font-semibold uppercase tracking-[0.16em] text-emerald-700">Цена за объем</p>
        <p v-for="level in product.levels.slice(0, 2)" :key="level.count" class="mt-2 min-w-0 truncate">
          От {{ level.count }} шт. · {{ formatMoney(level.price) }} · -{{ formatPercent(level.discount_percent) }}%
        </p>
      </div>
      <div v-else class="rounded-[20px] border border-dashed border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">
        Оптовых уровней скидки нет.
      </div>
    </div>

    <div class="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm">
      <span class="font-semibold text-brand">Открыть подробности</span>
      <span class="text-slate-400">Магазины и наличие</span>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { DiscountProduct } from '~/types/api'

const props = defineProps<{
  product: DiscountProduct
}>()

const emit = defineEmits<{
  open: [product: DiscountProduct]
}>()

const storeCountLabel = computed(() => `${props.product.stores_count} ${storesLabel(props.product.stores_count)}`)

function storesLabel(count: number) {
  if (count % 10 === 1 && count % 100 !== 11) return 'магазин'
  if ([2, 3, 4].includes(count % 10) && ![12, 13, 14].includes(count % 100)) return 'магазина'
  return 'магазинов'
}

function formatMoney(value: number | null) {
  if (value === null || Number.isNaN(value)) return '-'
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(value)
}

function formatPercent(value: number) {
  return Math.round(value)
}
</script>
