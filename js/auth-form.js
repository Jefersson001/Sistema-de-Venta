(() => {
    'use strict';
    const button = document.querySelector('.auth-password-toggle');
    const input = document.getElementById('auth-password');
    if (!button || !input) return;
    button.hidden = false;
    button.addEventListener('click', () => {
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'Ocultar' : 'Mostrar';
        button.setAttribute('aria-pressed', String(visible));
    });
})();
