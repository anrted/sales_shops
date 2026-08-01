<template>
  <div class="space-y-6">
    <!-- Header Status Card -->
    <div class="panel-shell p-6">
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h3 class="text-xl font-bold text-slate-900">Система обновлений (Deployment Manager)</h3>
          <p class="mt-1 text-sm text-slate-500">Управление версиями, развертывание обновлений из Git и просмотр истории</p>
        </div>
        <div class="flex items-center gap-3">
          <button
            @click="checkUpdates"
            :disabled="checking || isUpdating"
            class="btn-secondary"
          >
            <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': checking }" />
            <span>Проверить обновления</span>
          </button>
        </div>
      </div>

      <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <div class="context-card">
          <p class="context-card__label">Текущий коммит</p>
          <p class="context-card__value font-mono text-brand">
            {{ updateCheck?.short_commit || '...' }}
          </p>
        </div>
        <div class="context-card">
          <p class="context-card__label">Статус обновлений</p>
          <p class="context-card__value">
            <span v-if="updateCheck?.has_update" class="inline-flex items-center gap-1.5 text-amber-600 font-semibold">
              <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
              </span>
              Доступно обновление ({{ updateCheck.commits_behind }} комм.)
            </span>
            <span v-else-if="updateCheck" class="text-emerald-600 font-semibold inline-flex items-center gap-1">
              <CheckCircle2 class="h-4 w-4" /> Актуальная версия
            </span>
            <span v-else class="text-slate-400">Проверка...</span>
          </p>
        </div>
        <div class="context-card">
          <p class="context-card__label">Удаленный коммит</p>
          <p class="context-card__value font-mono text-slate-700">
            {{ updateCheck?.remote_commit?.substring(0, 7) || '...' }}
          </p>
        </div>
      </div>
    </div>

    <!-- Active Update / Terminal Progress -->
    <div v-if="activeUpdate" class="panel-shell p-6 border-brand/30 bg-slate-950 text-slate-100 rounded-3xl overflow-hidden shadow-2xl">
      <div class="flex items-center justify-between pb-4 border-b border-slate-800">
        <div class="flex items-center gap-3">
          <Loader2 v-if="activeUpdate.status === 'running' || activeUpdate.status === 'pending'" class="h-5 w-5 animate-spin text-brand" />
          <CheckCircle2 v-else-if="activeUpdate.status === 'success'" class="h-5 w-5 text-emerald-400" />
          <AlertTriangle v-else class="h-5 w-5 text-red-400" />
          <div>
            <h4 class="font-bold text-white text-base">
              Процесс обновления #{{ activeUpdate.id }}
            </h4>
            <p class="text-xs text-slate-400">Статус: <span class="uppercase font-semibold text-brand">{{ activeUpdate.status }}</span></p>
          </div>
        </div>
        <div class="text-xs font-mono text-slate-400">
          Target: {{ activeUpdate.target_commit.substring(0, 7) }}
        </div>
      </div>

      <div class="mt-4 bg-slate-900/90 rounded-2xl p-4 font-mono text-xs text-emerald-400 max-h-80 overflow-y-auto space-y-1">
        <pre class="whitespace-pre-wrap leading-relaxed">{{ activeUpdate.log_output || 'Инициализация процесса...' }}</pre>
        <div v-if="activeUpdate.error_message" class="text-red-400 mt-2 font-bold">
          [ОШИБКА]: {{ activeUpdate.error_message }}
        </div>
      </div>
    </div>

    <!-- Changelog & Start Update Card -->
    <div v-if="updateCheck?.has_update && !isUpdating" class="panel-shell p-6 bg-gradient-to-br from-white to-blue-50/30">
      <div class="flex items-center justify-between mb-4">
        <h4 class="text-lg font-bold text-slate-900">Список новых изменений (Changelog)</h4>
        <button
          @click="startUpdate"
          :disabled="starting"
          class="inline-flex items-center gap-2 rounded-2xl bg-brand px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 hover:bg-brand/90 transition-all disabled:opacity-50"
        >
          <Play class="h-4 w-4" />
          <span>Запустить обновление</span>
        </button>
      </div>

      <div class="divide-y divide-slate-200/70 border rounded-2xl bg-white/80 overflow-hidden shadow-sm">
        <div v-for="commit in updateCheck.changelog" :key="commit.hash" class="p-4 flex items-start justify-between gap-4">
          <div>
            <div class="flex items-center gap-2">
              <span class="font-mono text-xs bg-slate-100 text-slate-700 px-2 py-0.5 rounded font-semibold">{{ commit.hash }}</span>
              <span class="text-xs text-slate-500 font-medium">{{ commit.author }}</span>
            </div>
            <p class="mt-1 text-sm font-semibold text-slate-800">{{ commit.message }}</p>
          </div>
          <span class="text-xs text-slate-400 whitespace-nowrap">{{ commit.date }}</span>
        </div>
      </div>
    </div>

    <!-- History & Rollback Table -->
    <div class="panel-shell p-6">
      <h4 class="text-lg font-bold text-slate-900 mb-4">История обновлений и откатов</h4>

      <div class="table-shell rounded-2xl border border-slate-200 overflow-hidden">
        <table class="admin-table w-full text-sm">
          <thead>
            <tr>
              <th>ID</th>
              <th>Целевой коммит</th>
              <th>Предыдущий</th>
              <th>Статус</th>
              <th>Время</th>
              <th class="text-right">Действия</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in history" :key="item.id">
              <td class="font-semibold text-slate-900">#{{ item.id }}</td>
              <td class="font-mono text-xs text-brand font-bold">{{ item.target_commit.substring(0, 7) }}</td>
              <td class="font-mono text-xs text-slate-500">{{ item.previous_commit ? item.previous_commit.substring(0, 7) : '-' }}</td>
              <td>
                <span
                  class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wider"
                  :class="{
                    'bg-emerald-100 text-emerald-700': item.status === 'success',
                    'bg-amber-100 text-amber-700': item.status === 'running' || item.status === 'pending',
                    'bg-red-100 text-red-700': item.status === 'failed',
                    'bg-purple-100 text-purple-700': item.status === 'rolled_back'
                  }"
                >
                  {{ item.status }}
                </span>
              </td>
              <td class="text-slate-500 text-xs">
                {{ new Date(item.created_at).toLocaleString() }}
              </td>
              <td class="text-right">
                <button
                  v-if="item.status === 'success' && item.previous_commit && !isUpdating"
                  @click="rollback(item.previous_commit)"
                  class="text-xs font-semibold text-slate-600 hover:text-red-600 transition-colors bg-slate-100 px-3 py-1.5 rounded-xl border border-slate-200"
                >
                  Откатиться до {{ item.previous_commit.substring(0, 7) }}
                </button>
              </td>
            </tr>
            <tr v-if="!history.length">
              <td colspan="6" class="table-empty-state">История обновлений пуста</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { AlertTriangle, CheckCircle2, Loader2, Play, RefreshCw } from 'lucide-vue-next'
import { useApi } from '~/composables/useApi'

type UpdateCheckResponse = {
  current_commit: string
  short_commit: string
  remote_commit: string
  has_update: boolean
  commits_behind: number
  changelog: Array<{ hash: string; author: string; message: string; date: string }>
}

type UpdateRecord = {
  id: number
  target_commit: string
  previous_commit: string | null
  status: 'pending' | 'running' | 'success' | 'failed' | 'rolled_back'
  log_output: string | null
  error_message: string | null
  created_at: string
}

const api = useApi()

const checking = ref(false)
const starting = ref(false)
const updateCheck = ref<UpdateCheckResponse | null>(null)
const activeUpdate = ref<UpdateRecord | null>(null)
const history = ref<UpdateRecord[]>([])

let pollTimer: ReturnType<typeof setInterval> | null = null

const isUpdating = computed(() => {
  return activeUpdate.value && (activeUpdate.value.status === 'pending' || activeUpdate.value.status === 'running')
})

const checkUpdates = async () => {
  checking.value = true
  try {
    const res = await api<UpdateCheckResponse>('/admin/update/check')
    updateCheck.value = res
  } catch (e) {
    console.error('Failed to check updates', e)
  } finally {
    checking.value = false
  }
}

const fetchStatus = async () => {
  try {
    const res = await api<{ active_update: UpdateRecord | null; history: UpdateRecord[] }>('/admin/update/status')
    activeUpdate.value = res.active_update
    history.value = res.history || []
  } catch (e) {
    console.error('Failed to fetch status', e)
  }
}

const startUpdate = async () => {
  if (!confirm('Вы уверены, что хотите запустить процесс обновления приложения?')) return
  starting.value = true
  try {
    const res = await api<{ update: UpdateRecord }>('/admin/update/start', { method: 'POST' })
    activeUpdate.value = res.update
    startPolling()
  } catch (e) {
    alert('Ошибка запуска обновления')
  } finally {
    starting.value = false
  }
}

const rollback = async (commitHash: string) => {
  if (!confirm(`Вы действительно хотите откатить систему до коммита ${commitHash.substring(0, 7)}?`)) return
  try {
    const res = await api<{ update: UpdateRecord }>('/admin/update/rollback', {
      method: 'POST',
      body: { target_commit: commitHash }
    })
    activeUpdate.value = res.update
    startPolling()
  } catch (e) {
    alert('Ошибка запуска отката')
  }
}

const startPolling = () => {
  if (pollTimer) clearInterval(pollTimer)
  pollTimer = setInterval(async () => {
    await fetchStatus()
    if (!isUpdating.value) {
      stopPolling()
      checkUpdates()
    }
  }, 2000)
}

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

onMounted(async () => {
  await checkUpdates()
  await fetchStatus()
  if (isUpdating.value) {
    startPolling()
  }
})

onUnmounted(() => {
  stopPolling()
})
</script>
