import { chromium } from 'playwright'

const DESKTOP_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'

function parseArgs(argv) {
  const args = {
    url: 'https://lenta.com/',
    headed: false,
    timeout: 90,
    settleMs: 12000
  }

  for (const arg of argv) {
    if (arg === '--headed') {
      args.headed = true
      continue
    }

    if (arg.startsWith('--url=')) {
      args.url = arg.slice('--url='.length)
      continue
    }

    if (arg.startsWith('--timeout=')) {
      args.timeout = Number(arg.slice('--timeout='.length)) || args.timeout
      continue
    }

    if (arg.startsWith('--settle-ms=')) {
      args.settleMs = Number(arg.slice('--settle-ms='.length)) || args.settleMs
    }
  }

  return args
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function waitForCookies(context, timeoutMs) {
  const startedAt = Date.now()

  while (Date.now() - startedAt < timeoutMs) {
    const cookies = await context.cookies()
    const lentaCookies = cookies.filter((cookie) => cookie.domain.includes('lenta.com'))
    const hasQrator = lentaCookies.some((cookie) => cookie.name === 'qrator_jsr' || cookie.name === 'qrator_ssid')

    if (lentaCookies.length > 0 && hasQrator) {
      return lentaCookies
    }

    await sleep(1000)
  }

  return (await context.cookies()).filter((cookie) => cookie.domain.includes('lenta.com'))
}

const args = parseArgs(process.argv.slice(2))
const timeoutMs = Math.max(30, args.timeout) * 1000

const browser = await chromium.launch({
  headless: !args.headed,
  args: [
    '--disable-blink-features=AutomationControlled',
    '--disable-dev-shm-usage',
    '--no-default-browser-check'
  ]
})

try {
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    locale: 'ru-RU',
    timezoneId: 'Europe/Moscow',
    viewport: { width: 1440, height: 960 },
    userAgent: DESKTOP_USER_AGENT
  })

  await context.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', {
      get: () => undefined
    })

    Object.defineProperty(navigator, 'platform', {
      get: () => 'Win32'
    })

    Object.defineProperty(navigator, 'language', {
      get: () => 'ru-RU'
    })

    Object.defineProperty(navigator, 'languages', {
      get: () => ['ru-RU', 'ru']
    })
  })

  const page = await context.newPage()
  await page.setExtraHTTPHeaders({
    'accept-language': 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7'
  })
  await page.goto(args.url, { waitUntil: 'domcontentloaded', timeout: timeoutMs })
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {})
  await sleep(args.settleMs)

  const catalogUrl = new URL('/catalog/', args.url).toString()
  await page.goto(catalogUrl, { waitUntil: 'domcontentloaded', timeout: timeoutMs }).catch(() => {})
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
  await sleep(Math.min(args.settleMs, 8000))

  const cookies = await waitForCookies(context, 15000)
  const userAgent = await page.evaluate(() => navigator.userAgent)

  process.stdout.write(JSON.stringify({
    url: page.url(),
    title: await page.title(),
    userAgent,
    cookies: cookies.map((cookie) => ({
      name: cookie.name,
      value: cookie.value,
      domain: cookie.domain,
      path: cookie.path,
      expires: cookie.expires,
      httpOnly: cookie.httpOnly,
      secure: cookie.secure,
      sameSite: cookie.sameSite
    }))
  }))
} finally {
  await browser.close()
}
