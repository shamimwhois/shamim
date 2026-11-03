/**
 * Editor AI client.
 *
 * A thin wrapper over the `/editor/ai` endpoints, kept out of the Blade
 * template for two reasons: the fetch/CSRF/error-shape handling is the same for
 * all four calls and would otherwise be pasted four times into Alpine
 * attributes; and it is the only place that needs to know the error contract,
 * so a change to that contract touches one file.
 *
 * Everything here resolves or rejects with a plain object — no thrown strings,
 * no unparsed HTML error pages. A 419 (expired session), a 500 from an unlucky
 * deploy, and a network drop all arrive as the same `{ message, reason }` shape
 * the controller uses, so the UI has exactly one error path.
 */

const ENDPOINTS = {
    capabilities: '/editor/ai/capabilities',
    preview: '/editor/ai/preview',
    correct: '/editor/ai/correct',
    generate: '/editor/ai/generate',
    imagePrompt: '/editor/ai/image-prompt',
    image: '/editor/ai/image',
};

/**
 * The CSRF token, read from the meta tag rendered by partials/head.
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Perform a JSON request against the editor API.
 *
 * @param {string} key    One of the ENDPOINTS keys.
 * @param {object|null} body  Request payload, or null for a GET.
 * @returns {Promise<object>} The decoded response body.
 */
async function request(key, body = null) {
    const url = ENDPOINTS[key];

    if (!url) {
        throw { message: 'Unknown editor action.', reason: 'client_error' };
    }

    let response;

    try {
        response = await fetch(url, {
            method: body === null ? 'GET' : 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                // Tells Laravel to treat this as an AJAX request, so a
                // validation failure comes back as JSON rather than a redirect.
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: body === null ? undefined : JSON.stringify(body),
        });
    } catch {
        // The network itself failed — no response to inspect.
        throw { message: 'Could not reach the server. Check your connection.', reason: 'network_error' };
    }

    let payload = null;

    try {
        payload = await response.json();
    } catch {
        // A non-JSON body means something upstream failed hard (a 500 error
        // page, a gateway timeout). Do not try to show it to the user.
        payload = null;
    }

    if (!response.ok) {
        throw {
            message: payload?.message ?? `The request failed (${response.status}).`,
            reason: payload?.reason ?? 'http_error',
            // Validation errors arrive as a Laravel 422 body.
            errors: payload?.errors ?? null,
        };
    }

    return payload ?? {};
}

/**
 * The editor AI API surface.
 */
export const EditorAi = {
    /**
     * Which features this site can offer.
     *
     * Called once on mount. On a site with no provider key this resolves with
     * `configured: false` and the editor hides its AI affordances rather than
     * offering buttons that fail.
     */
    capabilities: () => request('capabilities'),

    /**
     * Render Markdown to sanitised HTML.
     *
     * Deliberately a server call: the preview must go through the same
     * MarkdownRenderer that serves the published post. A client-side renderer
     * would be a second sanitiser implementation, and two sanitisers drift —
     * at which point the preview stops predicting the published output, which
     * is the entire reason for having one.
     *
     * Note this works with no AI provider configured — it touches no model.
     */
    preview: (markdown) => request('preview', { markdown }),

    /**
     * Review a passage for corrections.
     *
     * @param {string} text
     * @param {string[]} types  Empty array means every type.
     */
    correct: (text, types = []) => request('correct', { text, types }),

    /**
     * Generate a draft.
     *
     * @param {object} payload  category, tone, subject, word_count, …
     */
    generate: (payload) => request('generate', payload),

    /**
     * Refine a rough idea into an image prompt.
     */
    imagePrompt: (idea, context = '', style = 'auto') =>
        request('image-prompt', { idea, context, style }),

    /**
     * Generate, store, and return a finished image.
     */
    image: (prompt, altText = '', size = null) =>
        request('image', { prompt, alt_text: altText, size }),
};

window.EditorAi = EditorAi;

export default EditorAi;
