<?php
// A separate, stable namespace per authenticated commercial session and role.
// It changes on sign-in/sign-out; drafts never cross accounts or requests.
$chatScopeKey='commercial_chat_draft_'.$chatContextSide;
$chatScope=$user?($_SESSION[$chatScopeKey]??=bin2hex(random_bytes(16))):'';
?>
<div hidden data-chat-context data-scope="<?= $e($chatScope) ?>" data-side="<?= $chatContextSide ?>" data-base="<?= $e(authBasePath()) ?>"></div>
