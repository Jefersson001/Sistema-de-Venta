const userPasswordDialog=document.querySelector('[data-user-password-dialog]');
if(userPasswordDialog) {
    document.querySelector('[data-open-user-password]')?.addEventListener('click',()=>userPasswordDialog.showModal());
    for(const button of userPasswordDialog.querySelectorAll('[data-close-user-password]'))button.addEventListener('click',()=>userPasswordDialog.close());
    if(userPasswordDialog.hasAttribute('data-reopen'))userPasswordDialog.showModal();
}
