<?php $editing=isset($record['id']);$administrator=($record['system_key']??null)==='administrator';$protected=$administrator?array_flip(accessAdministratorMinimum()):[];$resources=accessResources(); ?>
<section aria-labelledby="groups-title">
    <div class="contact-toolbar"><div>
        <?php if($page['view']==='form'): ?><a class="overview-link" href="grupos.php">← Grupos de acceso</a><?php endif; ?>
        <p class="eyebrow">AJUSTES</p><h1 id="groups-title"><?= $page['view']==='list'?'Grupos de acceso':$e($record['name']?:'Nuevo grupo') ?></h1>
    </div><?php if($page['view']==='list'): ?><a class="contact-button contact-button-primary" href="grupos.php?new=1">Nuevo</a><?php endif; ?></div>
    <p class="notice">Los permisos se aplican a los módulos, vistas, acciones y datos del Dashboard. Los grupos archivados no conceden acceso.</p>
    <?php if($page['view']==='list'): ?>
        <form class="contact-filters" action="grupos.php"><label>Buscar<input type="search" name="q" maxlength="120" value="<?= $e($page['search']) ?>" placeholder="Nombre o descripción"></label>
            <label>Estado<select name="active" data-auto-submit><option value="all" <?= $page['filter']==='all'?'selected':'' ?>>Todos</option><option value="active" <?= $page['filter']==='active'?'selected':'' ?>>Activos</option><option value="inactive" <?= $page['filter']==='inactive'?'selected':'' ?>>Archivados</option></select></label><button class="contact-button">Buscar</button>
        </form>
        <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Grupo</th><th>Descripción</th><th>Permisos</th><th>Estado</th></tr></thead><tbody>
            <?php foreach($page['rows'] as $group): ?><tr><td><a class="contact-name" href="grupos.php?id=<?= (int)$group['id'] ?>"><?= $e($group['name']) ?></a></td><td><?= $e($group['description']) ?></td><td><?= (int)$group['permission_count'] ?></td><td><span class="contact-tag"><?= $group['active']?'Activo':'Archivado' ?></span></td></tr><?php endforeach; ?>
            <?php if(!$page['rows']): ?><tr><td colspan="4" class="contact-empty">No hay grupos que coincidan con la búsqueda.</td></tr><?php endif; ?>
        </tbody></table></div>
        <nav class="contact-pagination" aria-label="Páginas de grupos"><span>Página <?= $page['currentPage'] ?> de <?= $page['pages'] ?></span><?php foreach(['Anterior'=>$page['currentPage']-1,'Siguiente'=>$page['currentPage']+1] as $label=>$number): ?><?php if($number>=1&&$number<=$page['pages']): ?><a class="contact-button" href="grupos.php?<?= $e(http_build_query(['q'=>$page['search'],'active'=>$page['filter'],'page'=>$number])) ?>"><?= $label ?></a><?php endif; ?><?php endforeach; ?></nav>
    <?php else: ?>
        <form class="contact-sheet" method="post" action="grupos.php?<?= $editing?'id='.(int)$record['id']:'new=1' ?>" data-access-group-form>
            <input type="hidden" name="csrf" value="<?= $e($page['csrf']) ?>"><input type="hidden" name="action" value="save_group"><input type="hidden" name="version" value="<?= (int)$record['version'] ?>"><?php if(!$editing): ?><input type="hidden" name="creation_key" value="<?= $e($page['creationKey']) ?>"><?php endif; ?>
            <div class="contact-form-grid"><label class="record-name-field">Nombre *<input name="name" required maxlength="120" value="<?= $e($record['name']) ?>"></label>
                <label class="access-active-field"><input type="checkbox" name="active" value="1" <?= $record['active']?'checked':'' ?> <?= $administrator?'disabled':'' ?>>Activo</label><?php if($administrator): ?><input type="hidden" name="active" value="1"><?php endif; ?>
                <label class="contact-full">Descripción<textarea name="description" rows="2" maxlength="1000"><?= $e($record['description']) ?></textarea></label>
            </div>
            <?php if($administrator): ?><p class="notice">Este grupo conserva la administración de Ajustes, usuarios y grupos. Sus demás permisos son configurables.</p><?php endif; ?>
            <p class="section-subtitle">Al combinar grupos se suman sus permisos. Sin un permiso concedido, el acceso queda denegado. «Propios» corresponde al vendedor relacionado con el usuario; «Todos» permite los registros de la sección.</p>
            <p class="section-subtitle">Generar una entrega no equivale a validarla. Registrar un pago no equivale a confirmarlo. El permiso del Dashboard también respetará los permisos de los datos que muestra.</p>
            <div class="contact-notebook" data-notebook>
                <nav class="notebook-tabs" data-notebook-tabs aria-label="Permisos por módulo">
                    <?php foreach(accessModules() as $module=>$label): ?><a id="access-tab-<?= $module ?>" href="#access-<?= $module ?>"><?= $e($label) ?><span class="access-tab-count" data-access-count="<?= $module ?>"></span></a><?php endforeach; ?>
                </nav>
                <?php foreach(accessModules() as $module=>$label): ?>
                    <section class="notebook-page" id="access-<?= $module ?>" data-notebook-page aria-labelledby="access-tab-<?= $module ?>">
                        <div class="contact-table-wrap"><table class="contact-table access-matrix"><thead><tr><th>Sección</th><th>Permisos</th><th>Registros</th></tr></thead><tbody>
                        <?php foreach($resources as $resource=>$definition): if($definition['module']!==$module)continue; $scope=$page['scopes'][$resource]??'all'; ?>
                            <tr data-access-row data-access-module="<?= $module ?>"><td><?= $e($definition['label']) ?></td><td><div class="access-permission-options">
                                <?php foreach($definition['actions'] as $action): $code=$resource.'.'.$action;$locked=isset($protected[$code]);$checked=$locked||isset($record['permissions'][$code]); ?>
                                    <label><input type="checkbox" name="permissions[]" value="<?= $e($code) ?>" data-access-permission="<?= $action ?>" <?= $checked?'checked':'' ?> <?= $locked?'disabled':'' ?>><span><?= $e(accessActions()[$action]) ?></span></label>
                                    <?php if($locked): ?><input type="hidden" name="permissions[]" value="<?= $e($code) ?>"><?php endif; ?>
                                <?php endforeach; ?>
                            </div></td><td>
                                <?php if($definition['allows_own']): ?><select name="scopes[<?= $e($resource) ?>]" data-access-scope aria-label="Registros de <?= $e($definition['label']) ?>"><option value="own" <?= $scope==='own'?'selected':'' ?>>Propios</option><option value="all" <?= $scope==='all'?'selected':'' ?>>Todos</option></select>
                                <?php else: ?>Todos<input type="hidden" name="scopes[<?= $e($resource) ?>]" value="all"><?php endif; ?>
                            </td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    </section>
                <?php endforeach; ?>
            </div>
            <div class="contact-form-actions"><button class="contact-button contact-button-primary">Guardar grupo</button><a class="contact-button" href="grupos.php">Descartar</a></div>
        </form>
        <script src="../../js/access-groups.js?v=<?= filemtime(dirname(__DIR__,2).'/js/access-groups.js') ?>" defer></script>
    <?php endif; ?>
</section>
