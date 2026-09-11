<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h2 class="page-title">Inventario</h2>
        <p class="page-subtitle">
            <?= Auth::isAdmin()
                ? 'Gestión de elementos del inventario'
                : 'Consulta de elementos del inventario' ?>
        </p>
    </div>
    <?php if (Auth::isAdmin()): ?>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= $config['base_url'] ?>/inventario/cargar" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-file-earmark-arrow-up"></i> Cargar plantilla
        </a>
        <a href="<?= $config['base_url'] ?>/movimientos/ingreso" class="btn btn-success btn-sm">
            <i class="bi bi-box-arrow-in-down"></i> Ingreso
        </a>
        <a href="<?= $config['base_url'] ?>/movimientos/prestamo" class="btn btn-warning btn-sm">
            <i class="bi bi-box-arrow-right"></i> Salida
        </a>
        <a href="<?= $config['base_url'] ?>/inventario/create" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Nuevo elemento
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php
$busqueda = $busqueda ?? '';
$categoriaId = $categoriaId ?? null;
$items = $items ?? [];
$page = max(1, (int) ($page ?? 1));
$totalPages = max(1, (int) ($totalPages ?? 1));
$total = (int) ($total ?? count($items));
$from = (int) ($from ?? ($items ? 1 : 0));
$to = (int) ($to ?? count($items));
$exportParams = [];
$listParams = [];
if ($busqueda !== '') {
    $exportParams['q'] = $busqueda;
    $listParams['q'] = $busqueda;
}
if ($categoriaId) {
    $exportParams['categoria'] = $categoriaId;
    $listParams['categoria'] = $categoriaId;
}
$pageUrl = static function (int $p) use ($config, $listParams): string {
    $params = $listParams;
    if ($p > 1) {
        $params['page'] = $p;
    }
    $query = $params ? '?' . http_build_query($params) : '';
    return $config['base_url'] . '/inventario' . $query;
};
?>

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="GET" action="<?= $config['base_url'] ?>/inventario" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label mb-1">Buscar elemento</label>
                <input type="search" name="q" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($busqueda) ?>"
                       placeholder="Código, nombre, descripción o categoría">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1">Categoría</label>
                <select name="categoria" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $cat): ?>
                    <option value="<?= (int) $cat['id_categoria'] ?>"
                        <?= $categoriaId === (int) $cat['id_categoria'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(CategoriaModel::formatLabel($cat['Nombre'] ?? '', $cat['Indicador'] ?? null)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search"></i> Buscar
                </button>
                <a href="<?= $config['base_url'] ?>/inventario/export-csv<?= $exportParams ? '?' . http_build_query($exportParams) : '' ?>"
                   class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Exportar CSV
                </a>
                <?php if ($busqueda !== '' || $categoriaId): ?>
                <a href="<?= $config['base_url'] ?>/inventario" class="btn btn-outline-secondary btn-sm">
                    Limpiar
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (empty($items)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-4">
        <?= $busqueda !== '' || $categoriaId
            ? 'No hay elementos que coincidan con la búsqueda.'
            : 'No hay elementos registrados.' ?>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
        <strong><i class="bi bi-box-seam"></i> Inventario</strong>
        <span class="badge bg-secondary"><?= $from ?>–<?= $to ?> de <?= $total ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-inventario mb-0 align-middle">
            <thead>
                <tr>
                    <th class="col-num">#</th>
                    <th class="photo-col">Foto</th>
                    <th>Código</th>
                    <th>Elemento</th>
                    <th>Categoría</th>
                    <th>Cantidad</th>
                    <th>Estado</th>
                    <?php if (Auth::isAdmin()): ?>
                    <th class="text-end">Acciones</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php $numero = $from; ?>
                <?php foreach ($items as $item): ?>
                <?php $foto = trim($item['Fotografia'] ?? ''); $fotoUrl = App::fileUrl($foto); ?>
                <tr>
                    <td class="col-num text-muted"><?= $numero++ ?></td>
                    <td class="photo-cell">
                        <?php if ($fotoUrl !== ''): ?>
                        <button type="button" class="photo-thumb-btn photo-thumb-wrap"
                                data-photo-src="<?= htmlspecialchars($fotoUrl) ?>"
                                data-photo-title="<?= htmlspecialchars($item['Elemento']) ?>"
                                title="Ver fotografía">
                            <img src="<?= htmlspecialchars($fotoUrl) ?>"
                                 alt="<?= htmlspecialchars($item['Elemento']) ?>"
                                 class="photo-thumb" width="32" height="32">
                        </button>
                        <?php else: ?>
                        <span class="photo-thumb-placeholder" title="Sin fotografía">
                            <i class="bi bi-image"></i>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($item['Codigo']) ?></code></td>
                    <td>
                        <strong><?= htmlspecialchars($item['Elemento']) ?></strong>
                        <?php if ($item['Descripcion']): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($item['Descripcion']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($item['Categoria'] ?? 'Sin categoría') ?>
                        <?php
                        $indCat = CategoriaModel::indicadorEfectivo($item['Categoria'] ?? '', $item['Categoria_indicador'] ?? null);
                        if ($indCat === 'Consumible'): ?>
                        <span class="badge bg-warning text-dark ms-1">Consumible</span>
                        <?php elseif ($indCat === 'No Consumible'): ?>
                        <span class="badge bg-info ms-1">No Consumible</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $item['Cantidad'] ?></td>
                    <td>
                        <span class="badge bg-<?= $item['Estado'] === 'Activo' ? 'success' : 'secondary' ?>">
                            <?= htmlspecialchars($item['Estado']) ?>
                        </span>
                    </td>
                    <?php if (Auth::isAdmin()): ?>
                    <td class="text-end">
                        <a href="<?= $config['base_url'] ?>/inventario/edit?codigo=<?= urlencode($item['Codigo']) ?>"
                           class="btn btn-sm btn-outline-primary" title="Editar">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" action="<?= $config['base_url'] ?>/inventario/delete"
                              class="d-inline" onsubmit="return confirm('¿Eliminar este elemento?')">
                            <input type="hidden" name="codigo" value="<?= htmlspecialchars($item['Codigo']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <span class="small text-muted">
            Mostrando <?= $from ?> a <?= $to ?> de <?= $total ?> elemento(s)
        </span>
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Paginación del inventario">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= $page <= 1 ? '#' : htmlspecialchars($pageUrl($page - 1)) ?>"
                       <?= $page <= 1 ? 'tabindex="-1" aria-disabled="true"' : '' ?>>Anterior</a>
                </li>
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $startPage + 4);
                $startPage = max(1, $endPage - 4);
                if ($startPage > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="<?= htmlspecialchars($pageUrl(1)) ?>">1</a>
                </li>
                <?php if ($startPage > 2): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; endif;
                for ($p = $startPage; $p <= $endPage; $p++): ?>
                <li class="page-item<?= $p === $page ? ' active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($pageUrl($p)) ?>"><?= $p ?></a>
                </li>
                <?php endfor;
                if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <li class="page-item">
                    <a class="page-link" href="<?= htmlspecialchars($pageUrl($totalPages)) ?>"><?= $totalPages ?></a>
                </li>
                <?php endif; ?>
                <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= $page >= $totalPages ? '#' : htmlspecialchars($pageUrl($page + 1)) ?>"
                       <?= $page >= $totalPages ? 'tabindex="-1" aria-disabled="true"' : '' ?>>Siguiente</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered photo-modal-dialog">
        <div class="modal-content photo-modal-content">
            <div class="modal-body p-0">
                <img src="" alt="" id="photoModalImage" class="photo-modal-image">
            </div>
        </div>
    </div>
</div>
