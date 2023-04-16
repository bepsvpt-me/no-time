/**
 * Welcome to Cloudflare Workers! This is your first worker.
 *
 * - Run `wrangler dev src/index.ts` in your terminal to start a development server
 * - Open a browser tab at http://localhost:8787/ to see your worker in action
 * - Run `wrangler publish src/index.ts --name my-worker` to publish your worker
 *
 * Learn more at https://developers.cloudflare.com/workers/
 */

export interface Env {
  NO_TIME: KVNamespace

  LINE_CHANNEL_ACCESS_TOKEN: string

  LINE_CHANNEL_SECRET: string

  API_ENDPOINT: string
}

type WebhookEvent = {
  type: string

  replyToken: string

  message: {
    type: string

    text: string
  }
}

type ReplyResponse = {
  ok: boolean

  url: string

  reply: {
    main: string

    comment: string
  }
}

const hmac = async (data: string, secret: string) => {
  const enc = new TextEncoder()

  const key = await crypto.subtle.importKey(
      'raw',
      enc.encode(secret),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['sign'],
  )

  const signature = await crypto.subtle.sign(
      'HMAC',
      key,
      enc.encode(data),
  )

  return Buffer.from(new Uint8Array(signature)).toString('base64')
}

const extractUrls = (text: string): string[] => {
  const regex = /https:\/\/(?:[\w-]+\.)+[a-z]{2,6}(?:\/[^\/\s]+)+/ig

  return text.match(regex) || []
}

const handleEvent = async (event: WebhookEvent, env: Env) => {
  if (event.type !== 'message' || event.message.type !== 'text') {
    return
  }

  const urls = extractUrls(event.message.text)

  if (!urls.length) {
    return
  }

  let url

  try {
    url = new URL(decodeURIComponent(urls[0]).trim())

    url.hash = ''

    url.searchParams.sort()
  } catch {
    return
  }

  const response = await fetch(`${env.API_ENDPOINT}/?url=${url.href}`)

  const data: ReplyResponse = await response.json()

  if (!data.ok) {
    return
  }

  return fetch('https://api.line.me/v2/bot/message/reply', {
    method: 'POST',
    headers: {
      authorization: `Bearer ${env.LINE_CHANNEL_ACCESS_TOKEN}`,
      'content-type': 'application/json',
    },
    body: JSON.stringify({
      replyToken: event.replyToken,
      notificationDisabled: true,
      messages: [{
        type: 'text',
        text: `
${data.url}
---
${data.reply.main}

${data.reply.comment || ''}
`.trim(),
      }],
    }),
  })
}

export default {
  async fetch(
    request: Request,
    env: Env,
    ctx: ExecutionContext
  ): Promise<Response> {
    const signature = request.headers.get('x-line-signature') || ''
    const payload = await request.text()

    let ok = false

    if (request.method === 'POST' && (await hmac(payload, env.LINE_CHANNEL_SECRET)) === signature) {
      const { events }: { events: WebhookEvent[] } = JSON.parse(payload)

      ctx.waitUntil(
          Promise.all(
              events.map((event) => handleEvent(event, env)),
          ),
      )

      ok = true
    }

    return new Response(JSON.stringify({ ok }), {
      headers: {
        'content-type': 'application/json;charset=UTF-8',
      },
    })
  },
}
