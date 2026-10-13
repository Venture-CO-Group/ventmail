/**
 * KÓDMINTÁK a fejlesztői dokumentációhoz (v1.1 · 7. modul, D177): curl, PHP, Node, Python.
 *
 * A mintát a végpontból (metódus, útvonal), a kötelező fejlécekből és a példa-törzsből
 * GENERÁLJUK — a referencia így nem tud elcsúszni a szerződéstől. A kulcs mindig
 * környezeti változóból jön (`VENTMAIL_API_KEY`), a mintában sosem szerepel valódi titok.
 */
export type SampleLanguage = 'curl' | 'php' | 'node' | 'python';

export interface SampleInput {
    method: string;
    url: string;
    body: unknown;
    idempotent: boolean;
}

const json = (value: unknown, indent = 2): string => JSON.stringify(value, null, indent);

function shellQuote(value: string): string {
    return `'${value.replace(/'/g, "'\\''")}'`;
}

export function sample(language: SampleLanguage, input: SampleInput): string {
    const hasBody = input.body !== null && input.body !== undefined && input.method !== 'GET';
    const body = hasBody ? json(input.body) : '';

    switch (language) {
        case 'curl': {
            const lines = [`curl -X ${input.method} ${input.url}`, '  -H "Authorization: Bearer $VENTMAIL_API_KEY"'];
            if (input.idempotent) lines.push('  -H "Idempotency-Key: $(uuidgen)"');
            if (hasBody) {
                lines.push('  -H "Content-Type: application/json"');
                lines.push(`  -d ${shellQuote(body)}`);
            }

            return lines.join(' \\\n');
        }

        case 'php': {
            const headers = ["'Authorization: Bearer '.getenv('VENTMAIL_API_KEY')"];
            if (input.idempotent) headers.push("'Idempotency-Key: '.bin2hex(random_bytes(16))");
            if (hasBody) headers.push("'Content-Type: application/json'");

            return [
                '<?php',
                `$ch = curl_init('${input.url}');`,
                'curl_setopt_array($ch, [',
                `    CURLOPT_CUSTOMREQUEST => '${input.method}',`,
                '    CURLOPT_RETURNTRANSFER => true,',
                `    CURLOPT_HTTPHEADER => [${headers.join(', ')}],`,
                ...(hasBody ? [`    CURLOPT_POSTFIELDS => json_encode(${phpArray(input.body, 1)}),`] : []),
                ']);',
                '$response = json_decode(curl_exec($ch), true);',
            ].join('\n');
        }

        case 'node': {
            const headers: string[] = ['Authorization: `Bearer ${process.env.VENTMAIL_API_KEY}`'];
            if (input.idempotent) headers.push("'Idempotency-Key': crypto.randomUUID()");
            if (hasBody) headers.push("'Content-Type': 'application/json'");

            return [
                `const response = await fetch('${input.url}', {`,
                `  method: '${input.method}',`,
                `  headers: { ${headers.join(', ')} },`,
                ...(hasBody ? [`  body: JSON.stringify(${json(input.body).replace(/\n/g, '\n  ')}),`] : []),
                '});',
                'const data = await response.json();',
            ].join('\n');
        }

        case 'python': {
            const headers = ['"Authorization": f"Bearer {os.environ[\'VENTMAIL_API_KEY\']}"'];
            if (input.idempotent) headers.push('"Idempotency-Key": str(uuid.uuid4())');

            return [
                'import os, uuid, requests',
                '',
                `response = requests.request(`,
                `    "${input.method}",`,
                `    "${input.url}",`,
                `    headers={${headers.join(', ')}},`,
                ...(hasBody ? [`    json=${pythonValue(input.body, 1)},`] : []),
                ')',
                'data = response.json()',
            ].join('\n');
        }
    }
}

function phpArray(value: unknown, depth: number): string {
    const pad = '    '.repeat(depth + 1);
    const end = '    '.repeat(depth);

    if (Array.isArray(value)) return `[${value.map((item) => phpArray(item, depth + 1)).join(', ')}]`;
    if (value !== null && typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>);
        if (!entries.length) return 'new stdClass()';

        return `[\n${entries.map(([key, item]) => `${pad}'${key}' => ${phpArray(item, depth + 1)}`).join(',\n')},\n${end}]`;
    }
    if (typeof value === 'string') return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
    if (value === null) return 'null';

    return String(value);
}

function pythonValue(value: unknown, depth: number): string {
    const pad = '    '.repeat(depth + 1);
    const end = '    '.repeat(depth);

    if (Array.isArray(value)) return `[${value.map((item) => pythonValue(item, depth + 1)).join(', ')}]`;
    if (value !== null && typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>);
        if (!entries.length) return '{}';

        return `{\n${entries.map(([key, item]) => `${pad}"${key}": ${pythonValue(item, depth + 1)}`).join(',\n')},\n${end}}`;
    }
    if (typeof value === 'boolean') return value ? 'True' : 'False';
    if (value === null) return 'None';
    if (typeof value === 'string') return JSON.stringify(value);

    return String(value);
}

/** A webhook-aláírás ellenőrzése a fogadó oldalon — nyelvenként. */
export function verifySample(language: SampleLanguage): string {
    switch (language) {
        case 'curl':
            return [
                '# A fogadó oldalon: HMAC-SHA256(secret, timestamp + "." + body)',
                'printf \'%s.%s\' "$TIMESTAMP" "$BODY" | openssl dgst -sha256 -hmac "$VENTMAIL_WEBHOOK_SECRET"',
            ].join('\n');
        case 'php':
            return [
                '<?php',
                "$timestamp = (int) $_SERVER['HTTP_X_VENTMAIL_TIMESTAMP'];",
                "$body = file_get_contents('php://input');",
                "$expected = hash_hmac('sha256', $timestamp.'.'.$body, getenv('VENTMAIL_WEBHOOK_SECRET'));",
                "$valid = abs(time() - $timestamp) <= 300",
                "    && hash_equals($expected, $_SERVER['HTTP_X_VENTMAIL_SIGNATURE']);",
            ].join('\n');
        case 'node':
            return [
                "import crypto from 'node:crypto';",
                '',
                'function verify(rawBody, headers) {',
                "  const timestamp = Number(headers['x-ventmail-timestamp']);",
                "  const expected = crypto.createHmac('sha256', process.env.VENTMAIL_WEBHOOK_SECRET)",
                "    .update(`${timestamp}.${rawBody}`).digest('hex');",
                "  const given = String(headers['x-ventmail-signature'] ?? '');",
                '  return Math.abs(Date.now() / 1000 - timestamp) <= 300',
                '    && given.length === expected.length',
                '    && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(given));',
                '}',
            ].join('\n');
        case 'python':
            return [
                'import hmac, hashlib, os, time',
                '',
                'def verify(raw_body: bytes, headers) -> bool:',
                '    timestamp = int(headers["X-VentMail-Timestamp"])',
                '    expected = hmac.new(os.environ["VENTMAIL_WEBHOOK_SECRET"].encode(),',
                '                        f"{timestamp}.".encode() + raw_body, hashlib.sha256).hexdigest()',
                '    return abs(time.time() - timestamp) <= 300 and hmac.compare_digest(',
                '        expected, headers["X-VentMail-Signature"])',
            ].join('\n');
    }
}
