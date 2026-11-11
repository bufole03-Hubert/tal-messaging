<?php
/** @var string $section */
/** @var string $heading */
/** @var string $lead */
$tabs = [
    'contacts' => ['/contacts', 'Contacts', 'address-book'],
    'groups' => ['/contacts/groups', 'Groupes', 'users-group'],
    'segments' => ['/contacts/segments', 'Segments', 'filter'],
    'imports' => ['/contacts/imports', 'Imports CSV', 'file-import'],
];
?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div>
    <h2 class="text-2xl font-bold md:text-3xl"><?= e($heading) ?></h2>
    <p class="mt-1 text-sm text-mute"><?= e($lead) ?></p>
  </div>
  <nav class="tabs self-start" aria-label="Sections contacts">
    <?php foreach ($tabs as $key => [$href, $label, $ic]): ?>
      <a class="tab" href="<?= e(url($href)) ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>><?= icon($ic, 'text-base') ?><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</div>
