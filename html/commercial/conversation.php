<?php
$chatId=(int)($chatSide==='admin'?$record['id']:$row['id']);
$chatPeer=$chatSide==='admin'?$record['full_name']:'Equipo Konta';
$chatInitial=mb_strtoupper(mb_substr(trim($chatPeer),0,1));
?>
<div class="demo-chat" data-demo-chat data-url="<?= $e($base.'/conversacion-demo.php?'.http_build_query(['side'=>$chatSide,'request'=>$chatId])) ?>" data-side="<?= $chatSide ?>" data-actor="<?= (int)$user['id'] ?>" data-csrf="<?= $e($_SESSION['auth_csrf']) ?>">
    <header class="demo-chat-heading"><span class="chat-peer-avatar" aria-hidden="true"><?= $e($chatInitial) ?></span><div class="chat-peer-info"><h2><?= $e($chatPeer) ?></h2><p>Solicitud #<?= $chatId ?> · Conversación privada</p></div><span class="chat-connection" data-chat-connection aria-live="polite">Conectando…</span></header>
    <button type="button" class="site-button button-outline chat-older" data-chat-older hidden>Ver mensajes anteriores</button>
    <div class="chat-feed-wrap"><div class="demo-chat-feed" data-chat-feed role="log" tabindex="0" aria-label="Mensajes de la solicitud" aria-live="polite" aria-relevant="additions"><p class="chat-empty">Abre esta pestaña para consultar la conversación.</p></div><button type="button" class="chat-new-messages" data-chat-new hidden aria-live="polite">Nuevos mensajes ↓</button></div>
    <div class="chat-error" data-chat-error role="alert" hidden></div>
    <div class="chat-composer"><label class="visually-hidden" for="demo-chat-text">Tu mensaje</label><div class="chat-compose-row"><textarea id="demo-chat-text" data-chat-text maxlength="2000" rows="1" placeholder="Escribe un mensaje…" aria-describedby="chat-compose-hint"></textarea><button class="site-button" type="button" data-chat-send aria-label="Enviar mensaje"><span data-chat-send-label>Enviar</span><?= kontaIcon('arrow') ?></button></div><footer><small id="chat-compose-hint"><?= kontaIcon('lock') ?>Solo tú y <?= $chatSide==='admin'?'el solicitante':'el equipo Konta' ?></small><small class="chat-keyboard-hint">Ctrl + Enter para enviar</small><small data-chat-count aria-label="Caracteres escritos">0 / 2000</small></footer></div>
    <noscript>Activa JavaScript para consultar y enviar mensajes.</noscript>
</div>
