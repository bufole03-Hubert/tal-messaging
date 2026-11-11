<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Contacts\Controllers\ContactController;
use App\Modules\Contacts\Controllers\DataController;
use App\Modules\Contacts\Controllers\GroupController;
use App\Modules\Contacts\Controllers\ImportController;
use App\Modules\Contacts\Controllers\SegmentController;

return static function (Router $r): void {
    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        $view = 'perm:view_contacts';
        $manage = ['csrf', 'perm:manage_contacts'];

        // ---- Pages ----
        $r->get('/contacts', [ContactController::class, 'page'], 'contacts.page', [$view]);
        $r->get('/contacts/groups', [GroupController::class, 'page'], 'contacts.groups_page', [$view]);
        $r->get('/contacts/segments', [SegmentController::class, 'page'], 'contacts.segments_page', [$view]);
        $r->get('/contacts/imports', [ImportController::class, 'page'], 'contacts.imports_page', [$view]);

        // ---- Contacts ----
        $r->get('/api/contacts', [ContactController::class, 'index'], 'contacts.list', [$view]);
        $r->get('/api/contacts/stats', [ContactController::class, 'stats'], 'contacts.stats', [$view]);
        $r->get('/api/contacts/tags', [ContactController::class, 'tags'], 'contacts.tags', [$view]);
        $r->get('/api/contacts/duplicates', [ContactController::class, 'duplicates'], 'contacts.duplicates', ['perm:manage_contacts']);
        $r->get('/api/contacts/export', [ContactController::class, 'export'], 'contacts.export', ['perm:export_contacts']);
        $r->post('/api/contacts/count', [ContactController::class, 'count'], 'contacts.count', ['csrf', $view]);
        $r->post('/api/contacts/bulk', [ContactController::class, 'bulk'], 'contacts.bulk', $manage);
        $r->post('/api/contacts/merge', [ContactController::class, 'merge'], 'contacts.merge', $manage);
        $r->post('/api/contacts', [ContactController::class, 'store'], 'contacts.create', $manage);
        $r->get('/api/contacts/{id}', [ContactController::class, 'show'], 'contacts.show', [$view]);
        $r->get('/api/contacts/{id}/history', [ContactController::class, 'history'], 'contacts.history', [$view]);
        $r->put('/api/contacts/{id}', [ContactController::class, 'update'], 'contacts.update', $manage);
        $r->delete('/api/contacts/{id}', [ContactController::class, 'destroy'], 'contacts.delete', $manage);

        // ---- Groupes ----
        $r->get('/api/contact-groups', [GroupController::class, 'index'], 'groups.list', [$view]);
        $r->post('/api/contact-groups', [GroupController::class, 'store'], 'groups.create', $manage);
        $r->get('/api/contact-groups/{id}', [GroupController::class, 'show'], 'groups.show', [$view]);
        $r->put('/api/contact-groups/{id}', [GroupController::class, 'update'], 'groups.update', $manage);
        $r->delete('/api/contact-groups/{id}', [GroupController::class, 'destroy'], 'groups.delete', $manage);

        // ---- Segments ----
        $r->get('/api/segments', [SegmentController::class, 'index'], 'segments.list', [$view]);
        $r->post('/api/segments', [SegmentController::class, 'store'], 'segments.create', $manage);
        $r->get('/api/segments/{id}', [SegmentController::class, 'show'], 'segments.show', [$view]);
        $r->put('/api/segments/{id}', [SegmentController::class, 'update'], 'segments.update', $manage);
        $r->delete('/api/segments/{id}', [SegmentController::class, 'destroy'], 'segments.delete', $manage);

        // ---- Imports ----
        $r->get('/api/contact-imports', [ImportController::class, 'index'], 'imports.list', [$view]);
        $r->post('/api/contact-imports', [ImportController::class, 'upload'], 'imports.upload', $manage);
        $r->get('/api/contact-imports/{id}', [ImportController::class, 'show'], 'imports.show', [$view]);
        $r->post('/api/contact-imports/{id}/start', [ImportController::class, 'start'], 'imports.start', $manage);
        $r->post('/api/contact-imports/{id}/process', [ImportController::class, 'process'], 'imports.process', $manage);
        $r->post('/api/contact-imports/{id}/cancel', [ImportController::class, 'cancel'], 'imports.cancel', $manage);
        $r->get('/api/contact-imports/{id}/errors', [ImportController::class, 'errors'], 'imports.errors', [$view]);
        $r->get('/api/contact-imports/{id}/errors.csv', [ImportController::class, 'errorsCsv'], 'imports.errors_csv', [$view]);

        // ---- Data & Compliance ----
        $r->get('/api/data/stats', [DataController::class, 'stats'], 'data.stats', ['perm:access_dashboard_data']);
        $r->get('/api/data/retention', [DataController::class, 'retention'], 'data.retention', ['perm:access_dashboard_data']);
        $r->put('/api/data/retention', [DataController::class, 'saveRetention'], 'data.retention_save', ['csrf', 'perm:manage_data_retention']);
        $r->post('/api/data/purge', [DataController::class, 'purge'], 'data.purge', ['csrf', 'perm:manage_data_retention', 'perm:view_all_tenants']);
    });
};
