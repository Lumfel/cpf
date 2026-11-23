function readXsrfCookie(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function apiRequest<T = Record<string, unknown>>(
    url: string,
    method: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
    body?: Record<string, unknown> | FormData,
): Promise<{ ok: boolean; status: number; data: T & { message?: string; errors?: Record<string, string[]> } }> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': readXsrfCookie(),
    };

    let payload: BodyInit | undefined;
    if (method !== 'GET') {
        if (body instanceof FormData) {
            payload = body;
        } else if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            payload = JSON.stringify(body);
        }
    }

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: payload,
    });

    const data = (await response.json().catch(() => ({}))) as T & {
        message?: string;
        errors?: Record<string, string[]>;
    };

    return {
        ok: response.ok,
        status: response.status,
        data,
    };
}
