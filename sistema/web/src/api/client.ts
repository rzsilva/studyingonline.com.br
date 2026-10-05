/**
 * Cliente HTTP da API.
 * - access token só em memória (não vai para localStorage: imune a roubo por XSS persistente)
 * - refresh token em cookie HttpOnly, renovado automaticamente quando a API responde 401
 */

const BASE = import.meta.env.VITE_API_URL ?? '/api';

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public code: string,
    public fields: Record<string, string> = {},
  ) {
    super(message);
  }
}

let accessToken: string | null = null;
let refreshing: Promise<boolean> | null = null;
let onSessionExpired: () => void = () => {};

export function setSessionExpiredHandler(fn: () => void) {
  onSessionExpired = fn;
}

export function setAccessToken(token: string | null) {
  accessToken = token;
}

function baseHeaders(): Record<string, string> {
  return { 'X-Instituicao-Host': window.location.hostname };
}

async function parseBody<T>(res: Response): Promise<{ data: T; meta?: Meta }> {
  if (res.status === 204) return { data: undefined as T };
  const body = await res.json().catch(() => null);
  if (!res.ok) {
    const e = body?.error ?? {};
    throw new ApiError(e.message ?? 'Falha na comunicação com o servidor.', res.status, e.code ?? 'error', e.fields);
  }
  return { data: body?.data as T, meta: body?.meta };
}

async function parse<T>(res: Response): Promise<T> {
  return (await parseBody<T>(res)).data;
}

export interface Meta {
  page: number;
  perPage: number;
  total: number;
}

/** Renova a sessão uma única vez mesmo com várias requisições simultâneas falhando. */
export function refreshSession(): Promise<boolean> {
  refreshing ??= fetch(`${BASE}/auth/refresh`, { method: 'POST', credentials: 'include', headers: baseHeaders() })
    .then(async (res) => {
      if (!res.ok) return false;
      const data = await parse<{ accessToken: string }>(res);
      accessToken = data.accessToken;
      return true;
    })
    .catch(() => false)
    .finally(() => {
      refreshing = null;
    });
  return refreshing;
}

type Init = RequestInit & { json?: unknown };

/** Envia com o access token; em 401 renova a sessão uma vez e repete. */
async function send(path: string, init: Init = {}, retry = true): Promise<Response> {
  const headers: Record<string, string> = { ...baseHeaders(), ...(init.headers as Record<string, string>) };
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`;
  let body = init.body;
  if (init.json !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(init.json);
  }

  const res = await fetch(`${BASE}${path}`, { ...init, headers, body, credentials: 'include' });

  if (res.status === 401 && retry && !path.startsWith('/auth/')) {
    if (await refreshSession()) return send(path, init, false);
    accessToken = null;
    onSessionExpired();
  }
  return res;
}

export async function api<T>(path: string, init: Init = {}): Promise<T> {
  return parse<T>(await send(path, init));
}

/** Para listagens paginadas: devolve também o meta (total, página). */
export async function apiWithMeta<T>(path: string): Promise<{ data: T; meta?: Meta }> {
  return parseBody<T>(await send(path));
}

/** Envio multipart (upload). */
export async function upload<T>(path: string, form: FormData): Promise<T> {
  return parse<T>(await send(path, { method: 'POST', body: form }));
}

/** Baixa arquivo autenticado e dispara o download no navegador. */
export async function download(path: string, fallbackName = 'arquivo'): Promise<void> {
  const res = await send(path);
  if (!res.ok) await parse(res);
  const blob = await res.blob();
  const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? fallbackName;
  const url = URL.createObjectURL(blob);
  const a = Object.assign(document.createElement('a'), { href: url, download: name });
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export const http = {
  get: <T>(path: string) => api<T>(path),
  post: <T>(path: string, json?: unknown) => api<T>(path, { method: 'POST', json }),
  put: <T>(path: string, json?: unknown) => api<T>(path, { method: 'PUT', json }),
  del: <T>(path: string) => api<T>(path, { method: 'DELETE' }),
};

/** Busca um recurso autenticado (ex.: imagem enviada) e devolve uma object URL. */
export async function blobUrl(path: string): Promise<string> {
  const res = await send(path);
  if (!res.ok) await parse(res);
  return URL.createObjectURL(await res.blob());
}
