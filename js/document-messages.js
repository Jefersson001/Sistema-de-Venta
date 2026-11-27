(() => {
  'use strict';
  const form = document.querySelector('#email-documents-form, #whatsapp-documents-form');
  const preview = document.querySelector('[data-document-message-preview], [data-share-message]');
  if (!form || !preview) return;
  let pending;
  form.addEventListener('submit', () => pending?.abort());
  form.addEventListener('change', async event => {
    if (event.target.name !== 'documents[]' || form.dataset.ready === '1') return;
    pending?.abort();
    const request = new AbortController();
    pending = request;
    const url = new URL(location.href);
    url.searchParams.delete('documents[]');
    url.searchParams.delete('share');
    url.searchParams.delete('log');
    url.searchParams.set('preview_messages', '1');
    for (const value of new FormData(form).getAll('documents[]')) url.searchParams.append('documents[]', value);
    preview.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {headers: {Accept: 'application/json'}, signal: request.signal});
      const result = await response.json();
      if (pending !== request || form.dataset.ready === '1') return;
      preview.textContent = result.message || 'Solo se compartirán los documentos seleccionados.';
    } catch (error) {
      if (error.name !== 'AbortError' && form.dataset.ready !== '1') preview.textContent = 'No se pudo actualizar la vista previa. Vuelve a seleccionar el contenido.';
    } finally {
      if (pending === request) preview.removeAttribute('aria-busy');
    }
  });
})();
