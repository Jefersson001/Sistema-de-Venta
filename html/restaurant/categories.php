<?php
// Keep the Inventory hierarchy, including parents with products only in descendants.
$catalogCategories=$page['productCategories']??[];
$productCategoryIds=array_map('intval',array_column($page['products'],'category_id'));
$categoryNodes=[];
foreach($catalogCategories as $category){
    $ids=inventoryCategoryDescendants($catalogCategories,(int)$category['id']);
    $path=[];$visited=[];$node=$category;
    while($node&&!isset($visited[$node['id']])){
        $visited[$node['id']]=true;array_unshift($path,(int)$node['id']);
        $node=inventoryCategoryRecord($catalogCategories,inventoryId($node['parent_id']));
    }
    $categoryNodes[(int)$category['id']]=$category+['descendants'=>implode(',',$ids),'path'=>implode(',',$path)];
}
$categoryButton=static function(array $node,string $label,bool $all=false)use($h):void{
    ?><button type="button" data-pos-category="<?= (int)$node['id'] ?>" data-pos-category-ids="<?= $h($node['descendants']) ?>" data-pos-category-path="<?= $h($node['path']) ?>" <?= $all?'data-pos-category-all':'' ?> aria-pressed="false"><?= $h($label) ?></button><?php
};
?>
<div class="restaurant-pos-category-filter">
<span class="restaurant-pos-filter-label">Categoría principal</span>
<div class="restaurant-pos-categories" role="group" aria-label="Categoría principal">
<button type="button" class="is-active" data-pos-category="" data-pos-category-ids="" data-pos-category-path="" aria-pressed="true">Todos</button>
<?php foreach($categoryNodes as $node)if(!isset($categoryNodes[(int)$node['parent_id']]))$categoryButton($node,$node['name']); ?>
<?php if(in_array(0,$productCategoryIds,true)): ?><button type="button" data-pos-category="uncategorized" data-pos-category-ids="0" data-pos-category-path="" aria-pressed="false">Sin categoría</button><?php endif; ?>
</div>
<?php foreach($categoryNodes as $parent):$children=array_filter($categoryNodes,static fn($child)=>(int)$child['parent_id']===(int)$parent['id']);if(!$children)continue; ?>
<div class="restaurant-pos-subcategories" data-pos-subcategories="<?= (int)$parent['id'] ?>" hidden>
<span class="restaurant-pos-filter-label"><?= $h($parent['name']) ?></span>
<div class="restaurant-pos-categories" role="group" aria-label="Subcategorías de <?= $h($parent['name']) ?>">
<?php $categoryButton($parent,'Todos de '.$parent['name'],true);foreach($children as $child)$categoryButton($child,$child['name']); ?>
</div></div><?php endforeach; ?>
</div>
