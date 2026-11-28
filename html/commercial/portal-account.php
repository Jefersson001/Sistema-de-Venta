<?php
$accountIntro=$accountIntro??'Estos son los datos que utilizamos para contactarte.';
$accountFields=$accountFields??[['Nombre',$user['full_name']],['Empresa o nombre personal',$user['business_name']],['Correo electrónico',$user['email']],['Teléfono / WhatsApp',$user['phone']]];
$accountNote=$accountNote??'Esta cuenta permite gestionar tu demostración. El acceso al sistema se habilita por separado.';
?>
<h1 class="visually-hidden">Mi cuenta</h1>
<section id="mi-perfil" class="workspace-section workspace-account" aria-labelledby="account-title"><header class="workspace-section-heading"><div><h2 id="account-title">Mi cuenta</h2><p><?= $e($accountIntro) ?></p></div></header><dl class="workspace-account-data"><?php foreach($accountFields as [$fieldLabel,$fieldValue]): ?><div><dt><?= $e($fieldLabel) ?></dt><dd><?= $e($fieldValue) ?></dd></div><?php endforeach; ?></dl><?php require __DIR__.'/portal-password.php'; ?><p class="workspace-account-note"><?= kontaIcon('lock') ?><?= $e($accountNote) ?></p></section>
