(() => {
    'use strict';
    const button = document.querySelector('.auth-password-toggle');
    const input = document.getElementById('auth-password');
    if (!button || !input) return;
    button.hidden = false;
    button.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.textContent = show ? 'Ocultar' : 'Mostrar';
        button.setAttribute('aria-pressed', String(show));
    });
})();
