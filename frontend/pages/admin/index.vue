<template>
  <div class="admin-shell min-h-screen text-ink">
    <div class="admin-shell__glow admin-shell__glow--left" />
    <div class="admin-shell__glow admin-shell__glow--right" />

    <header class="relative border-b border-white/70 bg-white/65 backdrop-blur-xl">
      <div class="mx-auto max-w-[1600px] px-4 py-8 lg:py-10">
        <div class="hero-card">
          <div class="grid gap-8 xl:grid-cols-[minmax(0,1.35fr)_420px] xl:items-end">
            <div>
              <div class="flex items-center justify-between max-w-3xl">
                <div class="inline-flex items-center gap-2 rounded-full border border-white/70 bg-white/70 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-brand shadow-sm">
                  <Sparkles class="h-3.5 w-3.5" />
                  Control Room
                </div>
                <button @click="handleLogout" class="text-sm font-medium text-slate-500 hover:text-red-600 transition-colors px-3 py-1 bg-white/50 rounded-full border border-slate-200">
                  Выйти
                </button>
              </div>
              <h1 class="mt-4 max-w-3xl text-4xl font-bold tracking-tight text-slate-950 md:text-5xl">Управление скидками и источниками данных</h1>
              <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600 md:text-base">
                Все операционные сценарии собраны в одном экране: постановка задач в очередь, синхронизация магазинов, импорт с карты и контроль категорий по сетям.
              </p>

              <div class="mt-6 flex flex-wrap gap-3 text-sm">
                <div class="hero-chip">
                  <Activity class="h-4 w-4 text-emerald-600" />
                  Активных запусков: {{ activeRunsCount }}
                </div>
                <div class="hero-chip">
                  <MapPinned class="h-4 w-4 text-brand" />
                  Текущий город: {{ selectedCityName }}
                </div>
                <div class="hero-chip">
                  <Database class="h-4 w-4 text-amber-600" />
                  Активный раздел: {{ activeSectionMeta.label }}
                </div>
              </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
              <div class="metric-card">
                <div class="metric-card__icon bg-brand/12 text-brand">
                  <MapPinned class="h-5 w-5" />
                </div>
                <div>
                  <p class="metric-card__label">Города</p>
                  <p class="metric-card__value">{{ cities.length }}</p>
                </div>
              </div>
              <div class="metric-card">
                <div class="metric-card__icon bg-emerald-100 text-emerald-700">
                  <StoreIcon class="h-5 w-5" />
                </div>
                <div>
                  <p class="metric-card__label">Магазины</p>
                  <p class="metric-card__value">{{ storesTotal }}</p>
                </div>
              </div>
              <div class="metric-card">
                <div class="metric-card__icon bg-amber-100 text-amber-700">
                  <Play class="h-5 w-5" />
                </div>
                <div>
                  <p class="metric-card__label">В очереди</p>
                  <p class="metric-card__value">{{ activeRunsCount }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </header>

    <main class="relative mx-auto grid max-w-[1600px] gap-6 px-4 py-6 xl:grid-cols-[300px_minmax(0,1fr)]">
      <aside class="space-y-6 xl:sticky xl:top-6 xl:self-start">
        <nav class="admin-sidebar-card">
          <div class="mb-4 flex items-center justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Разделы</p>
              <h2 class="mt-1 text-lg font-semibold text-slate-950">Навигация</h2>
            </div>
            <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ navSections.length }}</span>
          </div>
          <button
            v-for="item in navSections"
            :key="item.id"
            class="admin-nav-button"
            :class="activeSection === item.id ? 'admin-nav-button--active' : 'admin-nav-button--idle'"
            @click="activeSection = item.id"
          >
            <span class="admin-nav-button__icon" :class="activeSection === item.id ? 'bg-white/18 text-white' : 'bg-slate-100 text-slate-500'">
              <component :is="item.icon" class="h-5 w-5" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold">{{ item.label }}</span>
              <span class="mt-0.5 block text-xs" :class="activeSection === item.id ? 'text-white/80' : 'text-slate-500'">{{ item.description }}</span>
            </span>
            <span class="rounded-full px-2 py-1 text-xs font-semibold" :class="activeSection === item.id ? 'bg-white/18 text-white' : 'bg-slate-100 text-slate-600'">
              {{ sectionBadge(item.id) }}
            </span>
          </button>

          <a href="/" class="btn-secondary mt-4 w-full justify-start">
            <ArrowLeft class="h-4 w-4" />
            На сайт
          </a>
        </nav>

        <section class="admin-sidebar-card">
          <div class="flex items-start gap-3">
            <div class="rounded-2xl bg-brand/10 p-3 text-brand">
              <Settings2 class="h-5 w-5" />
            </div>
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Контекст</p>
              <h3 class="mt-1 text-base font-semibold text-slate-950">Текущая сессия</h3>
              <p class="mt-1 text-sm leading-6 text-slate-600">Выбранный город и сеть влияют на запуск парсеров и рабочие сообщения в активном разделе.</p>
            </div>
          </div>

          <div class="mt-5 space-y-4">
            <label class="block">
              <span class="field-label">Город для запуска</span>
              <select v-model="form.cityId" class="admin-input" required>
                <option :value="0">Выберите город</option>
                <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
              </select>
            </label>

            <label class="block">
              <span class="field-label">Сеть по умолчанию</span>
              <select v-model="form.chain" class="admin-input" required>
                <option value="">Сеть</option>
                <option v-for="chain in chains" :key="chain.code" :value="chain.code">{{ chain.name }}</option>
              </select>
            </label>
          </div>

          <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
            <div class="context-card">
              <p class="context-card__label">Город</p>
              <p class="context-card__value">{{ selectedCityName }}</p>
            </div>
            <div class="context-card">
              <p class="context-card__label">Сеть</p>
              <p class="context-card__value">{{ selectedChainName }}</p>
            </div>
          </div>
        </section>

      </aside>

      <div class="space-y-6">
        <section v-if="activeSection === 'parsing'" id="parsing" class="panel-shell overflow-hidden">
          <div class="p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <h2 class="text-xl font-bold">Запуск парсера</h2>
                <p class="mt-1 text-sm text-muted">Задача ставится в очередь, история запусков находится ниже на этой же странице.</p>
              </div>
              <form class="grid gap-3 lg:grid-cols-[190px_190px_160px]" @submit.prevent="runParser">
                <select v-model="form.chain" class="focus-ring rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" required>
                  <option value="">Сеть</option>
                  <option v-for="chain in chains" :key="chain.code" :value="chain.code">{{ chain.name }}</option>
                </select>
                <select v-model="form.cityId" class="focus-ring rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" required>
                  <option :value="0">Выберите город</option>
                  <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
                </select>
                <button class="focus-ring inline-flex items-center justify-center gap-2 rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="submitting || !hasSelectedCity">
                  <Loader2 v-if="submitting" class="h-4 w-4 animate-spin" />
                  <Play v-else class="h-4 w-4" />
                  {{ submitting ? 'Очередь' : 'Запустить для города' }}
                </button>
              </form>
            </div>
            <p v-if="submitMessage" class="mt-4 rounded-md px-3 py-2 text-sm font-semibold" :class="submitError ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'">{{ submitMessage }}</p>
          </div>

          <div class="border-t border-slate-200">
            <div class="flex flex-col gap-4 px-5 py-4">
              <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div>
                  <h3 class="text-lg font-bold">История запусков</h3>
                  <p class="mt-1 text-sm text-muted">Живой журнал задач с быстрым поиском, фильтром по статусу и сводкой по результатам.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <span class="table-stat-chip table-stat-chip--running">Активных: {{ activeRunsCount }}</span>
                  <span class="table-stat-chip">Всего: {{ runs.length }}</span>
                  <button class="focus-ring inline-flex items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold disabled:opacity-60" :disabled="refreshingRuns" @click="refreshRunList">
                    <Loader2 v-if="refreshingRuns" class="h-4 w-4 animate-spin" />
                    <RefreshCw v-else class="h-4 w-4" />
                    Обновить
                  </button>
                </div>
              </div>

              <div class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_220px]">
                <label class="block">
                  <span class="field-label">Поиск по истории</span>
                  <input v-model="runSearchQuery" class="admin-input mt-2" placeholder="Сеть, шаг, ошибка или ID запуска" type="search" />
                </label>
                <label class="block">
                  <span class="field-label">Статус</span>
                  <select v-model="runStatusFilter" class="admin-input mt-2">
                    <option value="all">Все статусы</option>
                    <option value="queued">В очереди</option>
                    <option value="running">Выполняется</option>
                    <option value="cancel_requested">Останавливается</option>
                    <option value="cancelled">Остановлено</option>
                    <option value="success">Готово</option>
                    <option value="error">Ошибка</option>
                  </select>
                </label>
              </div>
            </div>

            <div class="border-t border-slate-200 bg-slate-50/40 px-5 py-3">
              <div class="grid gap-3 md:grid-cols-3">
                <div class="table-summary-card">
                  <span class="table-summary-card__label">В работе</span>
                  <strong class="table-summary-card__value">{{ runStatusCount('queued') + runStatusCount('running') + runStatusCount('cancel_requested') }}</strong>
                </div>
                <div class="table-summary-card">
                  <span class="table-summary-card__label">Завершено</span>
                  <strong class="table-summary-card__value">{{ runStatusCount('success') }}</strong>
                </div>
                <div class="table-summary-card">
                  <span class="table-summary-card__label">С ошибкой</span>
                  <strong class="table-summary-card__value">{{ runStatusCount('error') }}</strong>
                </div>
              </div>
            </div>

            <div class="table-shell">
              <div class="overflow-x-auto">
                <table class="admin-table min-w-full text-sm">
                  <thead>
                    <tr>
                      <th class="w-[180px]">Запуск</th>
                      <th class="w-[150px]">Статус</th>
                      <th class="min-w-[320px]">Прогресс</th>
                      <th class="w-[90px] text-right">Магазины</th>
                      <th class="w-[90px] text-right">Товары</th>
                      <th class="w-[170px]">Создан</th>
                      <th class="w-[180px]">Действия</th>
                    </tr>
                  </thead>
                  <tbody v-if="filteredRuns.length">
                    <tr v-for="run in filteredRuns" :key="run.id" class="align-top">
                      <td>
                        <div class="space-y-1">
                          <p class="font-semibold text-slate-900">{{ run.chain?.name || '-' }}</p>
                          <p class="text-xs font-medium text-slate-500">Запуск #{{ run.id }}</p>
                        </div>
                      </td>
                      <td>
                        <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="statusClass(run.status)">{{ statusLabel(run.status) }}</span>
                      </td>
                      <td>
                        <div class="space-y-2">
                          <p class="font-semibold text-slate-700">{{ run.current_step || 'Шаг не зафиксирован' }}</p>
                          <p v-if="run.error_message" class="rounded-2xl border border-red-100 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {{ run.error_message }}
                          </p>
                          <p v-else class="text-sm text-slate-500">{{ runProgressSummary(run) }}</p>
                          <p v-if="run.heartbeat_at" class="text-xs font-medium text-slate-500">Heartbeat: {{ formatDate(run.heartbeat_at) }}</p>
                        </div>
                      </td>
                      <td class="text-right font-semibold text-slate-700">{{ formatCount(run.stores_count) }}</td>
                      <td class="text-right font-semibold text-slate-700">{{ formatCount(run.products_count) }}</td>
                      <td>
                        <div class="space-y-1">
                          <p class="font-medium text-slate-700">{{ formatDate(run.created_at) }}</p>
                          <p class="text-xs text-slate-500">{{ formatRelativeTime(run.created_at) }}</p>
                        </div>
                      </td>
                      <td>
                        <div class="flex flex-wrap items-center gap-2">
                          <button v-if="canCancel(run)" class="focus-ring rounded-xl border border-red-200 px-3 py-2 text-xs font-semibold text-red-700 disabled:opacity-60" :disabled="cancellingIds.has(run.id)" @click="cancelParser(run)">
                            {{ cancellingIds.has(run.id) ? 'Останавливаю' : 'Остановить' }}
                          </button>
                          <button v-if="canDelete(run)" class="focus-ring rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-60" :disabled="deletingIds.has(run.id)" @click="deleteRun(run)">
                            {{ deletingIds.has(run.id) ? 'Удаляю' : 'Удалить' }}
                          </button>
                          <span v-if="!canCancel(run) && !canDelete(run)" class="text-xs text-muted">Нет действий</span>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div v-if="!filteredRuns.length" class="table-empty-state">
                <p class="font-semibold text-slate-900">Запуски не найдены</p>
                <p class="mt-1 text-sm text-slate-500">Измените строку поиска или фильтр по статусу, чтобы увидеть задачи.</p>
              </div>
            </div>
          </div>
        </section>

        <section v-show="activeSection === 'magnit'" id="magnit" class="panel-shell overflow-hidden">
          <div class="flex flex-col gap-4 border-b border-slate-200 p-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <h2 class="text-xl font-bold">Магазины Магнита</h2>
              <p class="mt-1 text-sm text-muted">Отдельно можно синхронизировать магазины, категории и запустить основной парсер Магнита.</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button class="focus-ring inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold disabled:opacity-60" :disabled="syncingMagnitStores" @click="syncMagnitStores">
                <Loader2 v-if="syncingMagnitStores" class="h-4 w-4 animate-spin" />
                <RefreshCw v-else class="h-4 w-4" />
                {{ syncingMagnitStores ? 'Синхронизирую магазины' : 'Магазины' }}
              </button>
              <button class="focus-ring inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold disabled:opacity-60" :disabled="syncingMagnitCategories" @click="syncMagnitStoreCategories">
                <Loader2 v-if="syncingMagnitCategories" class="h-4 w-4 animate-spin" />
                <RefreshCw v-else class="h-4 w-4" />
                {{ syncingMagnitCategories ? 'Импортирую категории' : 'Импорт категорий' }}
              </button>
              <button class="focus-ring inline-flex items-center justify-center gap-2 rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="runningMagnitParser || !hasSelectedCity" @click="runMagnitParser">
                <Loader2 v-if="runningMagnitParser" class="h-4 w-4 animate-spin" />
                <Play v-else class="h-4 w-4" />
                {{ runningMagnitParser ? 'Ставлю в очередь' : 'Запустить Магнит для города' }}
              </button>
            </div>
          </div>

          <div class="grid gap-0 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div class="p-5">
              <div class="flex flex-wrap gap-3">
                <button class="focus-ring inline-flex items-center justify-center gap-2 rounded-md bg-brand px-3 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="yandexLoading" @click="searchYandexStores">
                  <Loader2 v-if="yandexLoading" class="h-4 w-4 animate-spin" />
                  <Search v-else class="h-4 w-4" />
                  Обновить область
                </button>
                <button class="focus-ring inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold disabled:opacity-60" :disabled="!yandexStores.length || importingYandexStores" @click="importYandexStores">
                  <Loader2 v-if="importingYandexStores" class="h-4 w-4 animate-spin" />
                  <Download v-else class="h-4 w-4" />
                  Импорт
                </button>
              </div>

              <ClientOnly>
                <div ref="mapContainer" class="mt-4 h-[460px] overflow-hidden rounded-md border border-slate-200 bg-slate-100"></div>
              </ClientOnly>

              <p v-if="yandexMessage" class="mt-3 rounded-md px-3 py-2 text-sm font-semibold" :class="yandexError ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'">{{ yandexMessage }}</p>
            </div>

            <div class="border-t border-slate-200 bg-slate-50 p-5 xl:border-l xl:border-t-0">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <h3 class="font-bold">Найдено на карте</h3>
                  <p class="text-sm text-muted">{{ filteredYandexStores.length }} из {{ yandexStores.length }}</p>
                </div>
                <input v-model="storeSearchQuery" class="focus-ring w-40 rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Фильтр" type="text" />
              </div>
              <div class="mt-4 max-h-[470px] space-y-2 overflow-y-auto pr-1">
                <button v-for="store in filteredYandexStores" :key="store.source_id" class="w-full rounded-md border border-slate-200 bg-white p-3 text-left hover:border-brand" @click="focusYandexStore(store)">
                  <p class="font-semibold">{{ store.name || 'Магнит' }}</p>
                  <p class="mt-1 text-sm text-muted">{{ store.address || 'Адрес не указан' }}</p>
                  <p class="mt-2 text-xs text-slate-500">{{ formatCoords(store.latitude, store.longitude) }}</p>
                </button>
                <p v-if="!filteredYandexStores.length" class="rounded-md border border-dashed border-slate-300 px-3 py-8 text-center text-sm text-muted">Список пуст</p>
              </div>
            </div>
          </div>

          <div class="border-t border-slate-200 p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h3 class="text-lg font-bold">Магазины в базе</h3>
                <p class="mt-1 text-sm text-muted">Сохраненные точки Магнита из базы проекта.</p>
              </div>
              <input v-model="savedStoreSearchQuery" class="focus-ring w-full rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-72" placeholder="Адрес, город или ID" type="text" />
            </div>

            <div class="mt-4">
              <section class="rounded-md border border-slate-200 bg-slate-50">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
                  <div>
                    <h4 class="font-bold">Магнит</h4>
                    <p class="text-sm text-muted">{{ magnitSavedStores.length }} из {{ magnitSavedStoresTotal }}</p>
                  </div>
                  <button class="focus-ring inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold disabled:opacity-60" :disabled="refreshingStores" @click="refreshStoreList">
                    <Loader2 v-if="refreshingStores" class="h-4 w-4 animate-spin" />
                    <RefreshCw v-else class="h-4 w-4" />
                    Обновить
                  </button>
                </div>

                <div class="entity-list">
                  <article v-for="store in magnitSavedStores" :key="store.id" class="entity-list__item">
                    <div class="flex items-start justify-between gap-3">
                      <div>
                        <p class="font-semibold text-slate-900">{{ storeTitle(store) }}</p>
                        <p class="mt-1 text-sm text-muted">{{ store.address || 'Адрес не указан' }}</p>
                      </div>
                      <span class="shrink-0 rounded px-2 py-1 text-xs font-bold" :class="store.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                        {{ store.is_active ? 'Активен' : 'Неактивен' }}
                      </span>
                    </div>
                    <dl class="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-3">
                      <div>
                        <dt class="font-semibold uppercase tracking-wide">ID</dt>
                        <dd class="mt-1 break-all">{{ store.external_id }}</dd>
                      </div>
                      <div>
                        <dt class="font-semibold uppercase tracking-wide">Город</dt>
                        <dd class="mt-1">{{ store.city?.name || '-' }}</dd>
                      </div>
                      <div>
                        <dt class="font-semibold uppercase tracking-wide">Координаты</dt>
                        <dd class="mt-1">{{ formatCoords(store.latitude, store.longitude) }}</dd>
                      </div>
                    </dl>
                  </article>
                  <p v-if="!magnitSavedStores.length" class="entity-list__empty">В базе нет магазинов по текущему фильтру</p>
                </div>
              </section>
            </div>
          </div>

          <div class="border-t border-slate-200 p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h3 class="text-lg font-bold">Категории в базе</h3>
                <p class="mt-1 text-sm text-muted">Главные и дочерние категории Магнита, отдельно по типам магазинов.</p>
              </div>
              <input v-model="magnitCategorySearchQuery" class="focus-ring w-full rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-72" placeholder="Категория, ID или тип" type="text" />
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-2">
              <section v-for="group in magnitCategoryGroups" :key="group.key" class="rounded-md border border-slate-200 bg-slate-50">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
                  <div>
                    <h4 class="font-bold">{{ group.title }}</h4>
                    <p class="text-sm text-muted">{{ group.items.length }} из {{ group.total }}</p>
                  </div>
                  <span class="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">{{ group.rootCount }} главных</span>
                </div>

                <div class="entity-list entity-list--compact">
                  <article v-for="category in group.items" :key="category.id" class="entity-list__item" :style="{ paddingLeft: `${16 + category.depth * 18}px` }">
                    <div class="flex items-start justify-between gap-3">
                      <div>
                        <p class="font-semibold text-slate-900">{{ category.name }}</p>
                        <p class="mt-1 text-xs text-slate-500">ID: {{ category.external_id || category.id }}</p>
                      </div>
                      <span class="shrink-0 rounded px-2 py-1 text-xs font-bold" :class="category.depth === 0 ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600'">
                        {{ category.depth === 0 ? 'Главная' : 'Дочерняя' }}
                      </span>
                    </div>
                  </article>
                  <p v-if="!group.items.length" class="entity-list__empty">Категории не найдены</p>
                </div>
              </section>
            </div>
          </div>
        </section>

        <section v-if="activeSection === 'metro'" id="metro" class="panel-shell overflow-hidden">
          <div class="flex flex-col gap-4 border-b border-slate-200 p-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <h2 class="text-xl font-bold">Магазины METRO</h2>
              <p class="mt-1 text-sm text-muted">Загрузка всех магазинов METRO через API сети и просмотр сохраненных точек.</p>
            </div>
            <button class="focus-ring inline-flex items-center justify-center gap-2 rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="syncingMetroStores" @click="syncMetroStores">
              <Loader2 v-if="syncingMetroStores" class="h-4 w-4 animate-spin" />
              <Search v-else class="h-4 w-4" />
              {{ syncingMetroStores ? 'Ищу METRO' : 'Найти все магазины METRO' }}
            </button>
          </div>

          <div class="p-5">
            <p v-if="metroMessage" class="mb-4 rounded-md px-3 py-2 text-sm font-semibold" :class="metroError ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'">{{ metroMessage }}</p>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h3 class="text-lg font-bold">Магазины METRO в базе</h3>
                <p class="mt-1 text-sm text-muted">Сохраненные точки METRO из базы проекта.</p>
              </div>
              <input v-model="savedStoreSearchQuery" class="focus-ring w-full rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-72" placeholder="Адрес, город или ID" type="text" />
            </div>

            <section class="mt-4 rounded-md border border-slate-200 bg-slate-50">
              <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
                <div>
                  <h4 class="font-bold">METRO</h4>
                  <p class="text-sm text-muted">{{ metroSavedStores.length }} из {{ metroSavedStoresTotal }}</p>
                </div>
                <button class="focus-ring inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold disabled:opacity-60" :disabled="refreshingStores" @click="refreshStoreList">
                  <Loader2 v-if="refreshingStores" class="h-4 w-4 animate-spin" />
                  <RefreshCw v-else class="h-4 w-4" />
                  Обновить
                </button>
              </div>

              <div class="entity-list entity-list--tall">
                <article v-for="store in metroSavedStores" :key="store.id" class="entity-list__item">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <p class="font-semibold text-slate-900">{{ storeTitle(store) }}</p>
                      <p class="mt-1 text-sm text-muted">{{ store.address || 'Адрес не указан' }}</p>
                    </div>
                    <span class="shrink-0 rounded px-2 py-1 text-xs font-bold" :class="store.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                      {{ store.is_active ? 'Активен' : 'Неактивен' }}
                    </span>
                  </div>
                  <dl class="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-3">
                    <div>
                      <dt class="font-semibold uppercase tracking-wide">ID</dt>
                      <dd class="mt-1 break-all">{{ store.external_id }}</dd>
                    </div>
                    <div>
                      <dt class="font-semibold uppercase tracking-wide">Город</dt>
                      <dd class="mt-1">{{ store.city?.name || '-' }}</dd>
                    </div>
                    <div>
                      <dt class="font-semibold uppercase tracking-wide">Координаты</dt>
                      <dd class="mt-1">{{ formatCoords(store.latitude, store.longitude) }}</dd>
                    </div>
                  </dl>
                </article>
                <p v-if="!metroSavedStores.length" class="entity-list__empty">В базе нет магазинов по текущему фильтру</p>
              </div>
            </section>

            <div class="mt-6 border-t border-slate-200 pt-5">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <h3 class="text-lg font-bold">Категории METRO в базе</h3>
                  <p class="mt-1 text-sm text-muted">Главные и дочерние категории, которые сохранил парсер METRO.</p>
                </div>
                <input v-model="metroCategorySearchQuery" class="focus-ring w-full rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-72" placeholder="Категория или ID" type="text" />
              </div>

              <section class="mt-4 rounded-md border border-slate-200 bg-slate-50">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
                  <div>
                    <h4 class="font-bold">METRO</h4>
                    <p class="text-sm text-muted">{{ metroCategoryRows.length }} из {{ metroCategories.length }}</p>
                  </div>
                  <span class="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">{{ metroRootCategoriesCount }} главных</span>
                </div>

                <div class="entity-list entity-list--compact">
                  <article v-for="category in metroCategoryRows" :key="category.id" class="entity-list__item" :style="{ paddingLeft: `${16 + category.depth * 18}px` }">
                    <div class="flex items-start justify-between gap-3">
                      <div>
                        <p class="font-semibold text-slate-900">{{ category.name }}</p>
                        <p class="mt-1 text-xs text-slate-500">ID: {{ category.external_id || category.id }}</p>
                      </div>
                      <span class="shrink-0 rounded px-2 py-1 text-xs font-bold" :class="category.depth === 0 ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600'">
                        {{ category.depth === 0 ? 'Главная' : 'Дочерняя' }}
                      </span>
                    </div>
                  </article>
                  <p v-if="!metroCategoryRows.length" class="entity-list__empty">Категории не найдены</p>
                </div>
              </section>
            </div>
          </div>
        </section>

        <section v-if="activeSection === 'lenta'" id="lenta" class="panel-shell overflow-hidden">
          <div class="flex flex-col gap-4 border-b border-slate-200 p-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <h2 class="text-xl font-bold">Лента</h2>
              <p class="mt-1 text-sm text-muted">Pickup-магазины, категории и быстрый запуск парсера Ленты.</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button class="focus-ring inline-flex items-center justify-center gap-2 rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="syncingLentaStores" @click="syncLentaStores">
                <Loader2 v-if="syncingLentaStores" class="h-4 w-4 animate-spin" />
                <Search v-else class="h-4 w-4" />
                {{ syncingLentaStores ? 'Ищу Ленту' : 'Найти pickup-магазины' }}
              </button>
              <button class="focus-ring inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold disabled:opacity-60" :disabled="syncingLentaCategories" @click="syncLentaCategories">
                <Loader2 v-if="syncingLentaCategories" class="h-4 w-4 animate-spin" />
                <RefreshCw v-else class="h-4 w-4" />
                {{ syncingLentaCategories ? 'Гружу категории' : 'Категории' }}
              </button>
              <button class="focus-ring inline-flex items-center justify-center gap-2 rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="runningLentaParser || !hasSelectedCity" @click="runLentaParser">
                <Loader2 v-if="runningLentaParser" class="h-4 w-4 animate-spin" />
                <Play v-else class="h-4 w-4" />
                {{ runningLentaParser ? 'Ставлю в очередь' : 'Запустить Ленту для города' }}
              </button>
            </div>
          </div>

          <div class="p-5">
            <div v-if="lentaBusy" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              <div class="flex items-start gap-3">
                <Loader2 class="mt-0.5 h-4 w-4 shrink-0 animate-spin" />
                <div>
                  <p class="font-semibold">{{ lentaBusyTitle }}</p>
                  <p class="mt-1 text-amber-700">{{ lentaBusyDescription }}</p>
                </div>
              </div>
            </div>
            <p v-if="lentaMessage" class="mb-4 rounded-md px-3 py-2 text-sm font-semibold" :class="lentaError ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'">{{ lentaMessage }}</p>
            <p v-if="lentaSessionMessage" class="mb-4 rounded-md px-3 py-2 text-sm font-semibold" :class="lentaSessionError ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'">
              {{ lentaSessionMessage }}
            </p>

            <section class="rounded-3xl border border-slate-200 bg-slate-50/80 p-4 shadow-sm">
              <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div>
                  <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-lg font-bold">Сессия и anti-bot cookies</h3>
                    <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="lentaSession?.is_configured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                      {{ lentaSession?.is_configured ? 'Настроено' : 'Нужно обновление' }}
                    </span>
                  </div>
                  <p class="mt-1 max-w-3xl text-sm text-muted">
                    Этот блок обновляет `LENTA_RAW_COOKIE_HEADER`, `qrator_*`, `Utk_*` и связанные поля в `backend/.env`. Если cookies устарели, парсинг Ленты начинает получать `403`.
                  </p>
                </div>
                <div class="flex flex-wrap gap-2">
                  <button class="focus-ring inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold disabled:opacity-60" :disabled="refreshingLentaSession" @click="refreshLentaSessionSettings">
                    <Loader2 v-if="refreshingLentaSession" class="h-4 w-4 animate-spin" />
                    <RefreshCw v-else class="h-4 w-4" />
                    {{ refreshingLentaSession ? 'Обновляю через браузер' : 'Автообновить cookies' }}
                  </button>
                  <button class="focus-ring inline-flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 disabled:opacity-60" :disabled="refreshingLentaSession" @click="refreshLentaSessionSettings(true)">
                    <Loader2 v-if="refreshingLentaSession" class="h-4 w-4 animate-spin" />
                    <Search v-else class="h-4 w-4" />
                    {{ refreshingLentaSession ? 'Жду окно браузера' : 'Обновить в видимом браузере' }}
                  </button>
                  <button class="focus-ring inline-flex items-center gap-2 rounded-md bg-ink px-3 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="savingLentaSession" @click="saveLentaSessionSettings">
                    <Loader2 v-if="savingLentaSession" class="h-4 w-4 animate-spin" />
                    <Settings2 v-else class="h-4 w-4" />
                    {{ savingLentaSession ? 'Сохраняю' : 'Сохранить вручную' }}
                  </button>
                </div>
              </div>

              <div class="mt-4 grid gap-3 md:grid-cols-3">
                <div class="context-card">
                  <p class="context-card__label">Последнее обновление</p>
                  <p class="context-card__value">{{ lentaSession?.status?.updated_at ? formatDate(lentaSession.status.updated_at) : 'Пока нет' }}</p>
                </div>
                <div class="context-card">
                  <p class="context-card__label">Источник</p>
                  <p class="context-card__value">{{ lentaSessionStatusSource }}</p>
                </div>
                <div class="context-card">
                  <p class="context-card__label">Cookies</p>
                  <p class="context-card__value">{{ lentaSession?.cookie_count ?? 0 }}</p>
                </div>
              </div>

              <div class="mt-4 grid gap-4 xl:grid-cols-2">
                <label class="block">
                  <span class="field-label">Регион slug</span>
                  <input v-model="lentaSessionForm.default_domain" class="admin-input" placeholder="nkz" type="text" />
                </label>
                <label class="block">
                  <span class="field-label">Browser User-Agent</span>
                  <input v-model="lentaSessionForm.browser_user_agent" class="admin-input" placeholder="Mozilla/5.0 ..." type="text" />
                </label>
                <label class="block">
                  <span class="field-label">Device ID</span>
                  <input v-model="lentaSessionForm.device_id" class="admin-input" placeholder="UUID" type="text" />
                </label>
                <label class="block">
                  <span class="field-label">User Session ID</span>
                  <input v-model="lentaSessionForm.user_session_id" class="admin-input" placeholder="UUID" type="text" />
                </label>
                <label class="block">
                  <span class="field-label">Session Token</span>
                  <input v-model="lentaSessionForm.session_token" class="admin-input" placeholder="Utk_SessionToken" type="text" />
                </label>
                <label class="block">
                  <span class="field-label">IAP UID</span>
                  <input v-model="lentaSessionForm.iap_uid" class="admin-input" placeholder="iap.uid" type="text" />
                </label>
                <label class="block">
                  <span class="field-label">qrator_jsr</span>
                  <input v-model="lentaSessionForm.qrator_jsr" class="admin-input" type="text" />
                </label>
                <label class="block">
                  <span class="field-label">qrator_jsid</span>
                  <input v-model="lentaSessionForm.qrator_jsid" class="admin-input" type="text" />
                </label>
                <label class="block">
                  <span class="field-label">qrator_ssid</span>
                  <input v-model="lentaSessionForm.qrator_ssid" class="admin-input" type="text" />
                </label>
                <label class="block">
                  <span class="field-label">Utk_MrkGrpTkn</span>
                  <input v-model="lentaSessionForm.utk_marketing_group_token" class="admin-input" type="text" />
                </label>
                <label class="block xl:col-span-2">
                  <span class="field-label">Utk_SssTkn</span>
                  <input v-model="lentaSessionForm.utk_sss_token" class="admin-input" type="text" />
                </label>
                <label class="block">
                  <span class="field-label">GrowthBook user id</span>
                  <input v-model="lentaSessionForm.growthbook_user_id" class="admin-input" type="text" />
                </label>
                <label class="block xl:col-span-2">
                  <span class="field-label">GrowthBook experiments</span>
                  <textarea v-model="lentaSessionForm.growthbook_experiments" class="admin-input min-h-[96px]" placeholder="GrowthBook_experiments"></textarea>
                </label>
                <label class="block xl:col-span-2">
                  <span class="field-label">GrowthBook cookie experiments</span>
                  <textarea v-model="lentaSessionForm.growthbook_cookie_experiments" class="admin-input min-h-[96px]" placeholder="GrowthBook_Cookie_Experiments"></textarea>
                </label>
                <label class="block xl:col-span-2">
                  <span class="field-label">App_Cache_City</span>
                  <textarea v-model="lentaSessionForm.app_cache_city" class="admin-input min-h-[96px]" placeholder='{"id":147,"slug":"nkz"}'></textarea>
                </label>
                <label class="block xl:col-span-2">
                  <span class="field-label">LENTA_RAW_COOKIE_HEADER</span>
                  <textarea v-model="lentaSessionForm.raw_cookie_header" class="admin-input min-h-[180px] font-mono text-xs" placeholder="qrator_jsr=...; qrator_jsid=...; ..."></textarea>
                </label>
              </div>

              <p v-if="lentaSession?.raw_cookie_preview" class="mt-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500">
                Preview: {{ lentaSession.raw_cookie_preview }}
              </p>
            </section>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h3 class="text-lg font-bold">Pickup-магазины Ленты в базе</h3>
                <p class="mt-1 text-sm text-muted">Сохраненные точки Ленты из pickup API.</p>
              </div>
              <input v-model="savedStoreSearchQuery" class="focus-ring w-full rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-72" placeholder="Адрес, город или ID" type="text" />
            </div>

            <section class="mt-4 rounded-md border border-slate-200 bg-slate-50">
              <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
                <div>
                  <h4 class="font-bold">Лента</h4>
                  <p class="text-sm text-muted">{{ lentaSavedStores.length }} из {{ lentaSavedStoresTotal }}</p>
                </div>
                <button class="focus-ring inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold disabled:opacity-60" :disabled="refreshingStores" @click="refreshStoreList">
                  <Loader2 v-if="refreshingStores" class="h-4 w-4 animate-spin" />
                  <RefreshCw v-else class="h-4 w-4" />
                  Обновить
                </button>
              </div>

              <div class="entity-list">
                <article v-for="store in lentaSavedStores" :key="store.id" class="entity-list__item">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <p class="font-semibold text-slate-900">{{ storeTitle(store) }}</p>
                      <p class="mt-1 text-sm text-muted">{{ store.address || 'Адрес не указан' }}</p>
                    </div>
                    <span class="shrink-0 rounded px-2 py-1 text-xs font-bold" :class="store.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                      {{ store.is_active ? 'Активен' : 'Неактивен' }}
                    </span>
                  </div>
                  <dl class="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-4">
                    <div>
                      <dt class="font-semibold uppercase tracking-wide">ID</dt>
                      <dd class="mt-1 break-all">{{ store.external_id }}</dd>
                    </div>
                    <div>
                      <dt class="font-semibold uppercase tracking-wide">Город</dt>
                      <dd class="mt-1">{{ store.city?.name || '-' }}</dd>
                    </div>
                    <div>
                      <dt class="font-semibold uppercase tracking-wide">Тип</dt>
                      <dd class="mt-1">{{ store.type || '-' }}</dd>
                    </div>
                    <div>
                      <dt class="font-semibold uppercase tracking-wide">Координаты</dt>
                      <dd class="mt-1">{{ formatCoords(store.latitude, store.longitude) }}</dd>
                    </div>
                  </dl>
                </article>
                <p v-if="!lentaSavedStores.length" class="entity-list__empty">В базе нет магазинов по текущему фильтру</p>
              </div>
            </section>

            <div class="mt-6 border-t border-slate-200 pt-5">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <h3 class="text-lg font-bold">Категории Ленты в базе</h3>
                  <p class="mt-1 text-sm text-muted">Главные и дочерние категории pickup-каталога Ленты.</p>
                </div>
                <input v-model="lentaCategorySearchQuery" class="focus-ring w-full rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-72" placeholder="Категория или ID" type="text" />
              </div>

              <div class="mt-4 grid gap-4 xl:grid-cols-2">
                <section v-for="group in lentaCategoryGroups" :key="group.key" class="rounded-md border border-slate-200 bg-slate-50">
                  <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
                    <div>
                      <h4 class="font-bold">{{ group.title }}</h4>
                      <p class="text-sm text-muted">{{ group.items.length }} из {{ group.total }}</p>
                    </div>
                    <span class="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">{{ group.rootCount }} главных</span>
                  </div>

                  <div class="entity-list entity-list--compact">
                    <article v-for="category in group.items" :key="category.id" class="entity-list__item" :style="{ paddingLeft: `${16 + category.depth * 18}px` }">
                      <div class="flex items-start justify-between gap-3">
                        <div>
                          <p class="font-semibold text-slate-900">{{ category.name }}</p>
                          <p class="mt-1 text-xs text-slate-500">ID: {{ category.external_id || category.id }}</p>
                        </div>
                        <span class="shrink-0 rounded px-2 py-1 text-xs font-bold" :class="category.depth === 0 ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600'">
                          {{ category.depth === 0 ? 'Главная' : 'Дочерняя' }}
                        </span>
                      </div>
                    </article>
                    <p v-if="!group.items.length" class="entity-list__empty">Категории не найдены</p>
                  </div>
                </section>
              </div>
              <p v-if="!lentaCategoryGroups.length" class="mt-4 rounded-md border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-muted">Категории Ленты ещё не загружены для типов SM и HM.</p>
            </div>
          </div>
        </section>

        <!-- Updates Section -->
        <AdminUpdateManager v-if="activeSection === 'updates'" />

      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth'
})

import { useAuth } from '~/composables/useAuth'
import { Activity, ArrowLeft, Building2, Database, Download, Loader2, MapPinned, Play, RefreshCw, Search, Settings2, Sparkles, Store as StoreIcon } from 'lucide-vue-next'
import type { Category, Chain, City, ListResponse, Store } from '~/types/api'

const { logout } = useAuth()
const handleLogout = () => {
  logout()
}

type ParseRun = {
  id: number
  chain?: Chain
  status: string
  stores_count: number
  products_count: number
  offers_count: number
  error_message: string | null
  current_step: string | null
  heartbeat_at: string | null
  created_at: string
}

type MagnitRegion = {
  store_region: string | null
  store_boxes: Array<Record<string, number>>
  regions: string[]
}

type YandexStore = {
  source_id: string
  storeCode?: string
  storeType?: string | null
  name: string | null
  address: string | null
  latitude: number | null
  longitude: number | null
}

type CategoryRow = Category & {
  depth: number
}

type CategoryGroup = {
  key: string
  title: string
  total: number
  rootCount: number
  items: CategoryRow[]
}

type LentaSessionSettingsForm = {
  default_domain: string
  device_id: string
  user_session_id: string
  session_token: string
  raw_cookie_header: string
  qrator_jsr: string
  qrator_jsid: string
  qrator_ssid: string
  utk_marketing_group_token: string
  utk_sss_token: string
  growthbook_user_id: string
  growthbook_experiments: string
  growthbook_cookie_experiments: string
  app_cache_city: string
  iap_uid: string
  browser_user_agent: string
}

type LentaSessionState = {
  settings: LentaSessionSettingsForm
  is_configured: boolean
  cookie_count: number
  raw_cookie_preview: string
  status?: {
    source?: string
    updated_at?: string
    cookie_count?: number
    default_domain?: string
    message?: string
  }
}

const api = useApi()
const config = useRuntimeConfig()
const activeSection = ref<'magnit' | 'metro' | 'lenta' | 'parsing' | 'updates'>('parsing')
const selectedCityId = useCookie<number>('discounts_city_id', {
  default: () => 0,
  maxAge: 60 * 60 * 24 * 365,
  sameSite: 'lax'
})

const initialCityId = normalizeCityId(selectedCityId.value)
const form = reactive({ chain: '', cityId: initialCityId })
const submitting = ref(false)
const refreshingRuns = ref(false)
const refreshingStores = ref(false)
const runSearchQuery = ref('')
const runStatusFilter = ref<'all' | 'queued' | 'running' | 'cancel_requested' | 'cancelled' | 'success' | 'error'>('all')
const submitMessage = ref('')
const submitError = ref(false)
const cancellingIds = ref(new Set<number>())
const deletingIds = ref(new Set<number>())
const syncingMagnitStores = ref(false)
const syncingMagnitCategories = ref(false)
const runningMagnitParser = ref(false)
const syncingMetroStores = ref(false)
const syncingLentaStores = ref(false)
const syncingLentaCategories = ref(false)
const runningLentaParser = ref(false)
const mapContainer = ref<HTMLDivElement | null>(null)
const magnitRegionBoxes = ref<Array<Record<string, number>>>([])
const yandexStores = ref<YandexStore[]>([])
const storeSearchQuery = ref('')
const savedStoreSearchQuery = ref('')
const magnitCategorySearchQuery = ref('')
const metroCategorySearchQuery = ref('')
const lentaCategorySearchQuery = ref('')
const yandexLoading = ref(false)
const importingYandexStores = ref(false)
const yandexMessage = ref('')
const yandexError = ref(false)
const metroMessage = ref('')
const metroError = ref(false)
const lentaMessage = ref('')
const lentaError = ref(false)
const savingLentaSession = ref(false)
const refreshingLentaSession = ref(false)
const lentaSessionMessage = ref('')
const lentaSessionError = ref(false)
const lentaSessionForm = reactive<LentaSessionSettingsForm>({
  default_domain: '',
  device_id: '',
  user_session_id: '',
  session_token: '',
  raw_cookie_header: '',
  qrator_jsr: '',
  qrator_jsid: '',
  qrator_ssid: '',
  utk_marketing_group_token: '',
  utk_sss_token: '',
  growthbook_user_id: '',
  growthbook_experiments: '',
  growthbook_cookie_experiments: '',
  app_cache_city: '',
  iap_uid: '',
  browser_user_agent: ''
})
let runsPollTimer: ReturnType<typeof setInterval> | null = null
let magnitSearchDebounceTimer: ReturnType<typeof setTimeout> | null = null
let lastMagnitSearchAt = 0
let lastMagnitSearchKey = ''
let yandexMap: any = null
let yandexCollection: any = null
const magnitSearchCache = new Map<string, YandexStore[]>()

const navSections = [
  { id: 'parsing' as const, label: 'Парсинг', description: 'Очередь, статусы и ручной запуск', icon: Play },
  { id: 'magnit' as const, label: 'Магнит', description: 'Карта, импорт и категории', icon: MapPinned },
  { id: 'metro' as const, label: 'METRO', description: 'Синхронизация точек и категорий', icon: StoreIcon },
  { id: 'lenta' as const, label: 'Лента', description: 'Pickup-магазины и парсер', icon: StoreIcon },
  { id: 'updates' as const, label: 'Обновления', description: 'Версия, git и автоматический деплой', icon: RefreshCw }
]

const { data: citiesData, refresh: refreshCities } = await useAsyncData('admin-cities', () => api<ListResponse<City>>('/cities'))
const { data: chainsData } = await useAsyncData('admin-chains', () => api<ListResponse<Chain>>('/chains'))
const { data: runsData, refresh: refreshRuns } = await useAsyncData('parse-runs', () => api<ListResponse<ParseRun>>('/admin/parse-runs'))
const { data: storesData, refresh: refreshStores } = await useAsyncData('admin-stores', () => api<ListResponse<Store>>('/stores'))
const { data: magnitCategoriesData, refresh: refreshMagnitCategories } = await useAsyncData('admin-magnit-categories', () => api<ListResponse<Category>>('/categories', {
  query: { chain: 'magnit', top_only: false }
}))
const { data: metroCategoriesData } = await useAsyncData('admin-metro-categories', () => api<ListResponse<Category>>('/categories', {
  query: { chain: 'metro', top_only: false }
}))
const { data: lentaCategoriesData, refresh: refreshLentaCategories } = await useAsyncData('admin-lenta-categories', () => api<ListResponse<Category>>('/categories', {
  query: { chain: 'lenta', top_only: false }
}))
const { data: magnitRegionData } = await useAsyncData('admin-magnit-region', () => api<{ item: MagnitRegion }>('/admin/magnit/region'))
const { data: lentaSessionData, refresh: refreshLentaSession } = await useAsyncData('admin-lenta-session', () => api<{ item: LentaSessionState }>('/admin/lenta/session'))

const magnitRegion = computed(() => magnitRegionData.value?.item)
const cities = computed(() => citiesData.value?.items || [])
const chains = computed(() => chainsData.value?.items || [])
const runs = computed(() => runsData.value?.items || [])
const stores = computed(() => storesData.value?.items || [])
const hasSelectedCity = computed(() => normalizeCityId(form.cityId) > 0)
const magnitCategories = computed(() => magnitCategoriesData.value?.items || [])
const metroCategories = computed(() => metroCategoriesData.value?.items || [])
const lentaCategories = computed(() => (lentaCategoriesData.value?.items || []).filter((category) => ['SM', 'HM'].includes(category.store_type || '')))
const storesTotal = computed(() => storesData.value?.total ?? stores.value.length)
const activeRunsCount = computed(() => runs.value.filter((run) => ['queued', 'running', 'cancel_requested'].includes(run.status)).length)
const filteredRuns = computed(() => {
  const query = runSearchQuery.value.trim().toLowerCase()

  return runs.value.filter((run) => {
    const matchesStatus = runStatusFilter.value === 'all' || run.status === runStatusFilter.value
    if (!matchesStatus) return false
    if (!query) return true

    return [
      run.id,
      run.chain?.name,
      run.chain?.code,
      run.status,
      run.current_step,
      run.error_message
    ].some((value) => String(value || '').toLowerCase().includes(query))
  })
})
const selectedCityName = computed(() => cities.value.find((city) => city.id === normalizeCityId(form.cityId))?.name || 'Город не выбран')
const selectedChainName = computed(() => chains.value.find((chain) => chain.code === form.chain)?.name || 'Сеть не выбрана')
const activeSectionMeta = computed(() => navSections.find((item) => item.id === activeSection.value) || navSections[0])
const magnitSavedStores = computed(() => filterSavedStoresByChain('magnit'))
const metroSavedStores = computed(() => filterSavedStoresByChain('metro'))
const lentaSavedStores = computed(() => filterSavedStoresByChain('lenta'))
const magnitSavedStoresTotal = computed(() => stores.value.filter((store) => store.chain?.code === 'magnit').length)
const metroSavedStoresTotal = computed(() => stores.value.filter((store) => store.chain?.code === 'metro').length)
const lentaSavedStoresTotal = computed(() => stores.value.filter((store) => store.chain?.code === 'lenta').length)
const magnitCategoryGroups = computed(() => categoryGroupsByStoreType(magnitCategories.value, magnitCategorySearchQuery.value))
const metroCategoryRows = computed(() => filteredCategoryRows(metroCategories.value, metroCategorySearchQuery.value))
const metroRootCategoriesCount = computed(() => metroCategories.value.filter((category) => !category.parent_id || category.level === 0).length)
const lentaCategoryGroups = computed(() => categoryGroupsByStoreType(lentaCategories.value, lentaCategorySearchQuery.value))
const lentaSession = computed(() => lentaSessionData.value?.item || null)
const lentaSessionStatusSource = computed(() => {
  const source = lentaSession.value?.status?.source
  if (source === 'browser_refresh') return 'Автообновление браузером'
  if (source === 'manual') return 'Ручное сохранение'
  return 'Пока нет'
})
const lentaBusy = computed(() => syncingLentaStores.value || syncingLentaCategories.value || runningLentaParser.value)
const lentaBusyTitle = computed(() => {
  if (syncingLentaStores.value) return 'Идёт обновление pickup-магазинов Ленты'
  if (syncingLentaCategories.value) return 'Идёт обновление категорий Ленты'
  if (runningLentaParser.value) return 'Парсер Ленты ставится в очередь'
  return ''
})
const lentaBusyDescription = computed(() => {
  if (syncingLentaStores.value) return 'Запрос может выполняться заметно дольше обычного: Лента последовательно получает регионы и список магазинов. Пока индикатор крутится, процесс не завис.'
  if (syncingLentaCategories.value) return 'Категории загружаются отдельно для типов HM и SM. Это может занять до нескольких десятков секунд.'
  if (runningLentaParser.value) return 'После постановки задачи её статус появится в разделе истории запусков.'
  return ''
})
const filteredYandexStores = computed(() => {
  const query = storeSearchQuery.value.trim().toLowerCase()
  if (!query) return yandexStores.value
  return yandexStores.value.filter((store) => `${store.name || ''} ${store.address || ''} ${store.source_id}`.toLowerCase().includes(query))
})

function sectionBadge(sectionId: typeof navSections[number]['id']) {
  if (sectionId === 'parsing') return `${runs.value.length}`
  if (sectionId === 'magnit') return `${magnitSavedStoresTotal.value}`
  if (sectionId === 'metro') return `${metroSavedStoresTotal.value}`
  return `${lentaSavedStoresTotal.value}`
}

watch(magnitRegion, (region) => {
  if (!region) return
  if (!magnitRegionBoxes.value.length) magnitRegionBoxes.value = region.store_boxes
}, { immediate: true })

watch(() => form.cityId, (cityId) => {
  const normalizedCityId = normalizeCityId(cityId)
  selectedCityId.value = normalizedCityId
})

watch(cities, (items) => {
  if (items.length && form.cityId && !items.some((city) => city.id === form.cityId)) {
    form.cityId = 0
  }
}, { immediate: true })

watch(lentaSession, (session) => {
  if (!session?.settings) return
  Object.assign(lentaSessionForm, session.settings)
}, { immediate: true })

watch(activeSection, async (section) => {
  if (section === 'parsing') {
    await refreshRunList()
    startRunsPolling()
    return
  }

  if (section === 'lenta') {
    await refreshLentaSession()
  }

  stopRunsPolling()
})

async function runParser() {
  if (!hasSelectedCity.value) {
    submitError.value = true
    submitMessage.value = 'Сначала выберите один город для запуска парсинга.'
    return
  }

  submitting.value = true
  submitMessage.value = ''
  submitError.value = false
  try {
    await runParserForChain(form.chain, form.cityId || null)
    submitMessage.value = 'Задача поставлена в очередь.'
    await refreshRunList()
  } catch {
    submitError.value = true
    submitMessage.value = 'Не удалось поставить задачу в очередь.'
  } finally {
    submitting.value = false
  }
}

async function runParserForChain(chain: string, cityId: number | null = null) {
  await api('/admin/parse-runs', { method: 'POST', body: { chain, city_id: cityId } })
}

async function refreshRunList() {
  refreshingRuns.value = true
  await refreshRuns()
  refreshingRuns.value = false
}

function startRunsPolling() {
  if (runsPollTimer) return

  runsPollTimer = setInterval(async () => {
    await refreshRunList()
  }, 2500)
}

function stopRunsPolling() {
  if (!runsPollTimer) return
  clearInterval(runsPollTimer)
  runsPollTimer = null
}

async function refreshStoreList() {
  refreshingStores.value = true
  await refreshStores()
  refreshingStores.value = false
}

async function syncMagnitStores() {
  syncingMagnitStores.value = true
  yandexMessage.value = ''
  yandexError.value = false
  try {
    const response = await api<{ item: { cities: number, stores: number } }>('/admin/magnit/stores/sync', { method: 'POST' })
    yandexMessage.value = `API Магнита: городов ${response.item.cities}, магазинов ${response.item.stores}.`
    await Promise.all([refreshCities(), refreshStores()])
  } catch {
    metroMessage.value = 'Не удалось обновить магазины Metro.'
    yandexError.value = true
    yandexMessage.value = 'Не удалось обновить магазины через API Магнита.'
  } finally {
    metroMessage.value = ''
    metroError.value = false
    syncingMagnitStores.value = false
  }
}

async function syncMagnitStoreCategories() {
  syncingMagnitCategories.value = true
  yandexMessage.value = 'Импортирую категории Магнита с сайта...'
  yandexError.value = false
  try {
    const response = await api<{ item: { store_types: number, categories: number } }>('/admin/magnit/categories/sync', { method: 'POST' })
    yandexMessage.value = `Категории Магнита импортированы с сайта: типов магазинов ${response.item.store_types}, категорий ${response.item.categories}.`
    await refreshMagnitCategories()
  } catch {
    yandexError.value = true
    yandexMessage.value = 'Не удалось импортировать категории Магнита с сайта.'
  } finally {
    syncingMagnitCategories.value = false
  }
}

async function runMagnitParser() {
  if (!hasSelectedCity.value) {
    yandexError.value = true
    yandexMessage.value = 'Сначала выберите один город для запуска парсинга Магнита.'
    return
  }

  runningMagnitParser.value = true
  yandexMessage.value = ''
  yandexError.value = false
  try {
    await runParserForChain('magnit', form.cityId || null)
    yandexMessage.value = 'Парсер Магнита поставлен в очередь.'
    await refreshRunList()
  } catch {
    yandexError.value = true
    yandexMessage.value = 'Не удалось поставить парсер Магнита в очередь.'
  } finally {
    runningMagnitParser.value = false
  }
}

async function runLentaParser() {
  if (!hasSelectedCity.value) {
    lentaError.value = true
    lentaMessage.value = 'Сначала выберите один город для запуска парсинга Ленты.'
    return
  }

  runningLentaParser.value = true
  lentaMessage.value = 'Ставлю парсер Ленты в очередь...'
  lentaError.value = false
  try {
    await runParserForChain('lenta', form.cityId || null)
    lentaMessage.value = 'Парсер Ленты поставлен в очередь.'
    await refreshRunList()
  } catch {
    lentaError.value = true
    lentaMessage.value = 'Не удалось поставить парсер Ленты в очередь.'
  } finally {
    runningLentaParser.value = false
  }
}

async function saveLentaSessionSettings() {
  savingLentaSession.value = true
  lentaSessionMessage.value = ''
  lentaSessionError.value = false

  try {
    const response = await api<{ item: LentaSessionState }>('/admin/lenta/session', {
      method: 'POST',
      body: { ...lentaSessionForm }
    })

    lentaSessionData.value = response
    lentaSessionMessage.value = 'Настройки Lenta сохранены в backend/.env.'
  } catch {
    lentaSessionError.value = true
    lentaSessionMessage.value = 'Не удалось сохранить настройки Lenta.'
  } finally {
    savingLentaSession.value = false
  }
}

async function refreshLentaSessionSettings(headed: boolean = false) {
  refreshingLentaSession.value = true
  lentaSessionMessage.value = ''
  lentaSessionError.value = false

  try {
    const response = await api<{ item: LentaSessionState }>('/admin/lenta/session/refresh', {
      method: 'POST',
      body: {
        headed,
        timeout: headed ? 180 : 90
      }
    })

    lentaSessionData.value = response
    lentaSessionMessage.value = headed
      ? 'Cookies Ленты обновлены через видимый браузер и записаны в backend/.env.'
      : 'Cookies Ленты обновлены через браузерный сценарий и записаны в backend/.env.'
  } catch (error: any) {
    lentaSessionError.value = true
    lentaSessionMessage.value = error?.data?.message || 'Не удалось автоматически обновить cookies Ленты.'
  } finally {
    refreshingLentaSession.value = false
  }
}

async function syncLentaCategories() {
  syncingLentaCategories.value = true
  lentaMessage.value = 'Загружаю категории Ленты для типов HM и SM...'
  lentaError.value = false
  try {
    const response = await api<{ item: { store_types: number, categories: number } }>('/admin/lenta/categories/sync', { method: 'POST' })
    lentaMessage.value = `Категории Ленты: типов магазинов ${response.item.store_types}, категорий ${response.item.categories}.`
    await refreshLentaCategories()
  } catch {
    lentaError.value = true
    lentaMessage.value = 'Не удалось обновить категории Ленты.'
  } finally {
    syncingLentaCategories.value = false
  }
}

async function syncMetroStores() {
  syncingMetroStores.value = true
  metroMessage.value = ''
  metroError.value = false
  try {
    const response = await api<{ item: { cities: number, stores: number } }>('/admin/metro/stores/sync', { method: 'POST' })
    metroMessage.value = `Metro: городов ${response.item.cities}, магазинов ${response.item.stores}.`
    yandexMessage.value = `Metro: городов ${response.item.cities}, магазинов ${response.item.stores}.`
    await Promise.all([refreshCities(), refreshStores()])
  } catch {
    metroError.value = true
    metroMessage.value = 'Не удалось обновить магазины Metro.'
    yandexError.value = true
    yandexMessage.value = 'Не удалось обновить магазины Metro.'
  } finally {
    yandexMessage.value = ''
    yandexError.value = false
    syncingMetroStores.value = false
  }
}

async function syncLentaStores() {
  syncingLentaStores.value = true
  lentaMessage.value = 'Ищу pickup-магазины Ленты по всем доступным регионам...'
  lentaError.value = false
  try {
    const response = await api<{ item: { cities: number, stores: number } }>('/admin/lenta/stores/sync', { method: 'POST' })
    lentaMessage.value = `Лента: городов ${response.item.cities}, магазинов ${response.item.stores}.`
    await Promise.all([refreshCities(), refreshStores()])
  } catch {
    lentaError.value = true
    lentaMessage.value = 'Не удалось обновить pickup-магазины Ленты.'
  } finally {
    syncingLentaStores.value = false
  }
}

async function searchYandexStores() {
  yandexLoading.value = true
  yandexMessage.value = ''
  yandexError.value = false
  try {
    await initYandexMap()
    const box = currentMapBox()
    const searchKey = magnitSearchKey(box)

    if (!canSearchMagnitStores(box)) {
      yandexStores.value = []
      drawYandexStores()
      yandexMessage.value = 'Приблизьте карту, чтобы загрузить магазины Магнита в видимой области.'
      return
    }

    if (magnitSearchCache.has(searchKey)) {
      yandexStores.value = magnitSearchCache.get(searchKey) || []
      drawYandexStores()
      yandexMessage.value = `API Магнита: найдено ${yandexStores.value.length} магазинов в текущей области карты.`
      return
    }

    lastMagnitSearchAt = Date.now()
    lastMagnitSearchKey = searchKey
    const response = await api<{ item: { stores: Array<Record<string, unknown>> } }>('/admin/magnit/stores', {
      query: {
        lat1: box.lat1,
        lon1: box.lon1,
        lat2: box.lat2,
        lon2: box.lon2
      }
    })

    yandexStores.value = response.item.stores.map(magnitStoreFromApi)
    magnitSearchCache.set(searchKey, yandexStores.value)
    drawYandexStores()
    yandexMessage.value = `API Магнита: найдено ${yandexStores.value.length} магазинов в текущей области карты.`
  } catch {
    yandexError.value = true
    yandexMessage.value = 'Не удалось получить магазины через API Магнита для текущей области карты.'
  } finally {
    yandexLoading.value = false
  }
}

function scheduleMagnitStoresSearch() {
  if (magnitSearchDebounceTimer) {
    clearTimeout(magnitSearchDebounceTimer)
  }

  magnitSearchDebounceTimer = setTimeout(async () => {
    if (activeSection.value !== 'magnit' || yandexLoading.value) {
      return
    }

    const box = currentMapBox()
    const searchKey = magnitSearchKey(box)
    if (searchKey === lastMagnitSearchKey || !canSearchMagnitStores(box)) {
      return
    }

    const delay = Math.max(0, 2500 - (Date.now() - lastMagnitSearchAt))
    if (delay > 0) {
      magnitSearchDebounceTimer = setTimeout(() => {
        void searchYandexStores()
      }, delay)
      return
    }

    await searchYandexStores()
  }, 1000)
}

async function importYandexStores() {
  importingYandexStores.value = true
  yandexMessage.value = ''
  yandexError.value = false
  try {
    const magnitStores = yandexStores.value
      .filter((store) => store.storeCode)
      .map((store) => ({
        storeCode: store.storeCode,
        storeType: store.storeType,
        address: store.address,
        latitude: store.latitude,
        longitude: store.longitude
      }))

    if (magnitStores.length) {
      const response = await api<{ item: { cities: number, stores: number } }>('/admin/magnit/stores/import', {
        method: 'POST',
        body: { stores: magnitStores }
      })
      yandexMessage.value = `Импортировано из API Магнита: городов ${response.item.cities}, магазинов ${response.item.stores}.`
      await Promise.all([refreshCities(), refreshStores()])
      return
    }

    const response = await api<{ item: { cities: number, stores: number } }>('/admin/magnit/stores/import-yandex', {
      method: 'POST',
      body: { stores: yandexStores.value }
    })
    yandexMessage.value = `Импортировано из Яндекс.Карт: городов ${response.item.cities}, магазинов ${response.item.stores}.`
    await Promise.all([refreshCities(), refreshStores()])
  } catch {
    yandexError.value = true
    yandexMessage.value = 'Не удалось импортировать магазины из Яндекс.Карт.'
  } finally {
    importingYandexStores.value = false
  }
}

async function loadYandexMaps() {
  if (typeof window === 'undefined') throw new Error('Yandex Maps can only be loaded on the client')
  const globalAny = window as any
  if (globalAny.ymaps) return globalAny.ymaps

  const params = new URLSearchParams({ lang: 'ru_RU' })
  const apiKey = String(config.public.yandexMapsApiKey || '')
  if (apiKey) params.set('apikey', apiKey)

  await new Promise<void>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = `https://api-maps.yandex.ru/2.1/?${params.toString()}`
    script.async = true
    script.onload = () => resolve()
    script.onerror = () => reject(new Error('Yandex Maps script failed'))
    document.body.appendChild(script)
  })

  if (!globalAny.ymaps) throw new Error('Yandex Maps did not initialize')
  return globalAny.ymaps
}

async function initYandexMap() {
  if (!mapContainer.value) throw new Error('Map container is missing')
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
    if (normalizedBoxes().length) {
      yandexMap.setBounds(boundsFromBox(normalizedBoxes()[0]), { checkZoomRange: true, zoomMargin: 28 })
    }
    // Ensure the map fits its container and redraws
    yandexMap.container.fitToViewport()
    yandexMap.events.add('boundschange', scheduleMagnitStoresSearch)
    scheduleMagnitStoresSearch()
  } else {
    // Map already exists, ensure it fills container
    yandexMap.container.fitToViewport()
  }

  drawYandexRegion()
  return ymaps
}

function drawYandexRegion() {
  if (!yandexMap) return
  yandexMap.geoObjects.removeAll()
  const ymaps = (window as any).ymaps
  yandexCollection = new ymaps.GeoObjectCollection()
  yandexMap.geoObjects.add(yandexCollection)
  drawYandexStores()
}

function drawYandexStores() {
  if (!yandexCollection) return
  const ymaps = (window as any).ymaps
  yandexCollection.removeAll()
  for (const store of yandexStores.value) {
    if (store.latitude === null || store.longitude === null) continue
    yandexCollection.add(new ymaps.Placemark([store.latitude, store.longitude], {
      balloonContentHeader: store.name || 'Магнит',
      balloonContentBody: store.address || '',
      hintContent: store.name || 'Магнит'
    }, {
      preset: 'islands#redShoppingIcon'
    }))
  }
}

function focusYandexStore(store: YandexStore) {
  if (!yandexMap || store.latitude === null || store.longitude === null) return
  yandexMap.setCenter([store.latitude, store.longitude], 16, { duration: 250 })
}

function currentMapBox() {
  const bounds = yandexMap?.getBounds?.()
  if (Array.isArray(bounds) && bounds.length >= 2) {
    const south = Math.min(Number(bounds[0][0]), Number(bounds[1][0]))
    const north = Math.max(Number(bounds[0][0]), Number(bounds[1][0]))
    const west = Math.min(Number(bounds[0][1]), Number(bounds[1][1]))
    const east = Math.max(Number(bounds[0][1]), Number(bounds[1][1]))

    return {
      lat1: north,
      lon1: west,
      lat2: south,
      lon2: east
    }
  }

  const box = normalizedBoxes()[0]
  if (box) {
    return {
      lat1: box.north,
      lon1: box.west,
      lat2: box.south,
      lon2: box.east
    }
  }

  return {
    lat1: 56.0,
    lon1: 37.0,
    lat2: 55.4,
    lon2: 38.3
  }
}

function magnitSearchKey(box: ReturnType<typeof currentMapBox>) {
  const lat1 = Number(box.lat1)
  const lon1 = Number(box.lon1)
  const lat2 = Number(box.lat2)
  const lon2 = Number(box.lon2)
  if (!Number.isFinite(lat1) || !Number.isFinite(lon1) || !Number.isFinite(lat2) || !Number.isFinite(lon2)) {
    return '0:0:0:0'
  }
  return [
    lat1.toFixed(3),
    lon1.toFixed(3),
    lat2.toFixed(3),
    lon2.toFixed(3),
  ].join(':')
}

function canSearchMagnitStores(box: ReturnType<typeof currentMapBox>) {
  const zoom = Number(yandexMap?.getZoom?.() || 0)
  const latSpan = Math.abs(Number(box.lat1) - Number(box.lat2))
  const lonSpan = Math.abs(Number(box.lon2) - Number(box.lon1))

  return zoom >= 11 && latSpan <= 0.8 && lonSpan <= 1.0
}

function magnitStoreFromApi(store: Record<string, unknown>): YandexStore {
  const storeCode = String(store.storeCode || '')

  return {
    source_id: storeCode,
    storeCode,
    storeType: typeof store.storeType === 'string' ? store.storeType : null,
    name: storeCode ? `Магнит ${storeCode}` : 'Магнит',
    address: typeof store.address === 'string' ? store.address : null,
    latitude: store.latitude !== null && store.latitude !== undefined ? Number(store.latitude) : null,
    longitude: store.longitude !== null && store.longitude !== undefined ? Number(store.longitude) : null
  }
}

function normalizedBoxes() {
  return magnitRegionBoxes.value
    .map((box) => {
      const lat1 = Number(box.lat1)
      const lat2 = Number(box.lat2)
      const lon1 = Number(box.lon1)
      const lon2 = Number(box.lon2)
      return {
        south: Math.min(lat1, lat2),
        north: Math.max(lat1, lat2),
        west: Math.min(lon1, lon2),
        east: Math.max(lon1, lon2)
      }
    })
    .filter((box) => Number.isFinite(box.south) && Number.isFinite(box.north) && Number.isFinite(box.west) && Number.isFinite(box.east))
}

function boundsFromBox(box: ReturnType<typeof normalizedBoxes>[number]) {
  return [[box.south, box.west], [box.north, box.east]]
}

function mapCenter() {
  const box = normalizedBoxes()[0]
  if (!box) return [55.751244, 37.618423]
  return [(box.south + box.north) / 2, (box.west + box.east) / 2]
}

function filterSavedStoresByChain(chainCode: string) {
  const query = savedStoreSearchQuery.value.trim().toLowerCase()

  return stores.value
    .filter((store) => store.chain?.code === chainCode)
    .filter((store) => {
      if (!query) return true

      return [
        store.name,
        store.address,
        store.external_id,
        store.city?.name,
        formatCoords(store.latitude, store.longitude)
      ].some((value) => String(value || '').toLowerCase().includes(query))
    })
}

function storeTitle(store: Store) {
  return store.name || store.chain?.name || store.external_id
}

function categoryGroupsByStoreType(categories: Category[], searchQuery: string = ''): CategoryGroup[] {
  const grouped = new Map<string, Category[]>()

  for (const category of categories) {
    const key = category.store_type || 'default'
    grouped.set(key, [...(grouped.get(key) || []), category])
  }

  return Array.from(grouped.entries())
    .sort(([left], [right]) => left.localeCompare(right, 'ru'))
    .map(([key, items]) => ({
      key,
      title: key === 'default' ? 'Без типа магазина' : key,
      total: items.length,
      rootCount: items.filter((category) => !category.parent_id || category.level === 0).length,
      items: filteredCategoryRows(items, searchQuery)
    }))
}

function filteredCategoryRows(categories: Category[], searchQuery: string = ''): CategoryRow[] {
  const rows = flattenCategories(categories)
  const query = searchQuery.trim().toLowerCase()
  if (!query) return rows

  return rows.filter((category) => [
    category.name,
    category.external_id,
    category.store_type,
    category.chain?.name,
    category.chain?.code
  ].some((value) => String(value || '').toLowerCase().includes(query)))
}

function flattenCategories(categories: Category[]): CategoryRow[] {
  const byParent = new Map<number | null, Category[]>()
  const knownIds = new Set(categories.map((category) => category.id))

  for (const category of categories) {
    const parentId = category.parent_id && knownIds.has(category.parent_id) ? category.parent_id : null
    byParent.set(parentId, [...(byParent.get(parentId) || []), category])
  }

  for (const items of byParent.values()) {
    items.sort((left, right) => left.name.localeCompare(right.name, 'ru'))
  }

  const rows: CategoryRow[] = []
  const append = (parentId: number | null, depth: number) => {
    for (const category of byParent.get(parentId) || []) {
      rows.push({ ...category, depth })
      append(category.id, depth + 1)
    }
  }

  append(null, 0)

  return rows
}

function canCancel(run: ParseRun) {
  return ['queued', 'running'].includes(run.status)
}

function canDelete(run: ParseRun) {
  return ['success', 'error', 'cancelled'].includes(run.status)
}

async function cancelParser(run: ParseRun) {
  cancellingIds.value.add(run.id)
  try {
    await api(`/admin/parse-runs/${run.id}/cancel`, { method: 'POST' })
    await refreshRunList()
  } finally {
    cancellingIds.value.delete(run.id)
  }
}

async function deleteRun(run: ParseRun) {
  if (typeof window !== 'undefined' && !window.confirm(`Удалить запуск "${run.chain?.name || 'Без названия'}" из истории?`)) {
    return
  }

  deletingIds.value.add(run.id)
  try {
    await api(`/admin/parse-runs/${run.id}`, { method: 'DELETE' })
    await refreshRunList()
  } finally {
    deletingIds.value.delete(run.id)
  }
}

function statusClass(status: string) {
  return {
    queued: 'bg-blue-50 text-blue-700',
    running: 'bg-amber-50 text-amber-700',
    cancel_requested: 'bg-orange-50 text-orange-700',
    cancelled: 'bg-slate-100 text-slate-700',
    success: 'bg-emerald-50 text-emerald-700',
    error: 'bg-red-50 text-red-700'
  }[status] || 'bg-slate-100 text-slate-700'
}

function statusLabel(status: string) {
  return {
    queued: 'В очереди',
    running: 'Выполняется',
    cancel_requested: 'Останавливается',
    cancelled: 'Остановлено',
    success: 'Готово',
    error: 'Ошибка'
  }[status] || status
}

function runStatusCount(status: string) {
  return runs.value.filter((run) => run.status === status).length
}

function runProgressSummary(run: ParseRun) {
  return [
    `${formatCount(run.stores_count)} магазинов`,
    `${formatCount(run.products_count)} товаров`
  ].join(' • ')
}

function formatCount(value: number) {
  return new Intl.NumberFormat('ru-RU').format(value || 0)
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}

function formatRelativeTime(value: string) {
  const diffMs = new Date(value).getTime() - Date.now()
  const diffMinutes = Math.round(diffMs / 60000)
  const diffHours = Math.round(diffMs / 3600000)
  const diffDays = Math.round(diffMs / 86400000)
  const formatter = new Intl.RelativeTimeFormat('ru', { numeric: 'auto' })

  if (Math.abs(diffMinutes) < 60) return formatter.format(diffMinutes, 'minute')
  if (Math.abs(diffHours) < 24) return formatter.format(diffHours, 'hour')
  return formatter.format(diffDays, 'day')
}

function formatCoords(latitude: number | string | null | undefined, longitude: number | string | null | undefined) {
  const lat = latitude !== null && latitude !== undefined ? Number(latitude) : NaN
  const lon = longitude !== null && longitude !== undefined ? Number(longitude) : NaN
  if (!Number.isFinite(lat) || !Number.isFinite(lon)) return '-'
  return `${lat.toFixed(6)}, ${lon.toFixed(6)}`
}

function normalizeCityId(value: unknown) {
  const cityId = Number(value)
  return Number.isFinite(cityId) && cityId > 0 ? Math.trunc(cityId) : 0
}

onMounted(() => {
  if (activeSection.value === 'parsing') {
    startRunsPolling()
  }

  if (magnitRegion.value?.store_boxes?.length) magnitRegionBoxes.value = magnitRegion.value.store_boxes

  // Initialize Yandex map only when we are on the Magnit section
  if (activeSection.value === 'magnit') {
    initYandexMapSilently()
  }
})

onBeforeUnmount(() => {
  stopRunsPolling()
  if (magnitSearchDebounceTimer) clearTimeout(magnitSearchDebounceTimer)
  if (yandexMap) yandexMap.destroy()
})

// Initialize map without showing error message; errors will be handled by UI when needed
async function initYandexMapSilently() {
  if (!mapContainer.value) return
  try {
    await initYandexMap()
  } catch (e) {
    // Keep error state for UI to show; do not throw further
    yandexError.value = true
    yandexMessage.value = 'Яндекс.Карта не загрузилась. Для стабильной работы укажите NUXT_PUBLIC_YANDEX_MAPS_API_KEY.'
    console.warn('Yandex Maps init failed:', e)
  }
}

// Re-initialize map when switching to Magnit tab
watch(activeSection, (section) => {
  if (section === 'magnit') {
    // Reset error state before attempting to load
    yandexError.value = false
    yandexMessage.value = ''
    initYandexMapSilently()
  } else {
    // Dispose map when leaving the section to free resources
    if (yandexMap) {
      yandexMap.destroy()
      yandexMap = null
      yandexCollection = null
    }
  }
})
</script>

<style scoped>
.admin-shell {
  position: relative;
  overflow: hidden;
  background:
    radial-gradient(circle at top left, rgba(21, 112, 239, 0.16), transparent 30%),
    radial-gradient(circle at top right, rgba(14, 165, 233, 0.12), transparent 24%),
    linear-gradient(180deg, #f7fbff 0%, #eef4fb 48%, #f8fafc 100%);
}

.admin-shell__glow {
  position: absolute;
  z-index: 0;
  border-radius: 9999px;
  filter: blur(70px);
  opacity: 0.6;
  pointer-events: none;
}

.admin-shell__glow--left {
  top: -4rem;
  left: -3rem;
  height: 16rem;
  width: 16rem;
  background: rgba(21, 112, 239, 0.15);
}

.admin-shell__glow--right {
  top: 10rem;
  right: -4rem;
  height: 20rem;
  width: 20rem;
  background: rgba(14, 165, 233, 0.16);
}

.hero-card {
  @apply relative overflow-hidden rounded-[32px] border border-white/70 bg-white/70 p-6 shadow-[0_24px_80px_-32px_rgba(15,23,42,0.35)] backdrop-blur-xl md:p-8;
}

.hero-card::after {
  content: "";
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(255, 255, 255, 0.38), rgba(255, 255, 255, 0.05));
  pointer-events: none;
}

.hero-chip {
  @apply inline-flex items-center gap-2 rounded-full border border-white/80 bg-white/80 px-3 py-2 font-medium text-slate-700 shadow-sm;
}

.metric-card {
  @apply flex items-center gap-4 rounded-3xl border border-white/80 bg-white/80 px-4 py-4 shadow-sm backdrop-blur;
}

.metric-card__icon {
  @apply flex size-11 items-center justify-center rounded-2xl;
}

.metric-card__label {
  @apply text-xs font-semibold uppercase tracking-[0.18em] text-slate-500;
}

.metric-card__value {
  @apply mt-1 text-2xl font-bold text-slate-950;
}

.admin-sidebar-card,
.panel-shell {
  @apply relative border border-white/70 bg-white/90 shadow-[0_20px_70px_-35px_rgba(15,23,42,0.35)] backdrop-blur-xl;
}

.admin-sidebar-card {
  @apply rounded-[28px] p-5;
}

.panel-shell {
  @apply rounded-[30px];
}

.admin-nav-button {
  @apply flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left transition-all duration-200;
}

.admin-nav-button--idle {
  @apply text-slate-700 hover:bg-slate-100/90 hover:shadow-sm;
}

.admin-nav-button--active {
  background: linear-gradient(135deg, #1570ef 0%, #0f5bd2 100%);
  @apply text-white shadow-lg shadow-blue-200/80;
}

.admin-nav-button__icon {
  @apply flex size-10 items-center justify-center rounded-2xl transition-colors;
}

.btn-secondary {
  @apply inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white/90 px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm transition-all duration-200 hover:-translate-y-px hover:border-brand hover:text-brand hover:shadow-md;
}

.admin-input {
  @apply mt-2 w-full rounded-2xl border border-slate-200 bg-white/95 px-3.5 py-3 text-sm text-slate-800 shadow-sm transition-colors placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2;
}

.field-label {
  @apply text-xs font-semibold uppercase tracking-[0.18em] text-slate-500;
}

.context-card {
  @apply rounded-2xl border border-slate-200 bg-slate-50/90 px-4 py-3 shadow-sm;
}

.context-card__label {
  @apply text-xs font-semibold uppercase tracking-[0.16em] text-slate-500;
}

.context-card__value {
  @apply mt-1 text-sm font-semibold text-slate-900;
}

.panel-shell :is(select, input[type="text"], input[type="search"]) {
  @apply rounded-xl border-slate-200 bg-white/95 shadow-sm transition-colors placeholder:text-slate-400;
}

.panel-shell button {
  @apply transition-all duration-200 shadow-sm;
}

.panel-shell button:not(:disabled):hover {
  @apply -translate-y-px shadow-md;
}

.panel-shell tbody tr,
.panel-shell article {
  @apply transition-colors;
}

.panel-shell tbody tr:hover,
.panel-shell article:hover {
  @apply bg-slate-50/75;
}

.table-shell {
  @apply overflow-hidden;
}

.admin-table {
  @apply border-separate border-spacing-0;
}

.admin-table thead th {
  @apply sticky top-0 z-10 border-b border-slate-200 bg-slate-50/95 px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 backdrop-blur;
}

.admin-table tbody td {
  @apply border-b border-slate-200/80 px-5 py-4 align-top;
}

.admin-table tbody tr:nth-child(even) {
  @apply bg-slate-50/40;
}

.table-stat-chip {
  @apply inline-flex items-center rounded-full border border-slate-200 bg-white/90 px-3 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-slate-600 shadow-sm;
}

.table-stat-chip--running {
  @apply border-amber-200 bg-amber-50 text-amber-700;
}

.table-summary-card {
  @apply rounded-2xl border border-white/70 bg-white/80 px-4 py-3 shadow-sm;
}

.table-summary-card__label {
  @apply text-xs font-semibold uppercase tracking-[0.18em] text-slate-500;
}

.table-summary-card__value {
  @apply mt-2 block text-2xl font-bold text-slate-950;
}

.table-empty-state {
  @apply border-t border-slate-200 bg-white px-5 py-12 text-center;
}

.entity-list {
  @apply max-h-[420px] divide-y divide-slate-200 overflow-y-auto;
}

.entity-list--compact {
  @apply max-h-[360px];
}

.entity-list--tall {
  @apply max-h-[560px];
}

.entity-list__item {
  @apply bg-white px-4 py-3;
}

.entity-list__empty {
  @apply bg-white px-4 py-10 text-center text-sm text-muted;
}
</style>
