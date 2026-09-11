<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h2 class="page-title">Categorías</h2>
        <p class="page-subtitle">Clasificación de elementos. General es no consumible; las demás indican si son de consumo.</p>
    </div>
    <a href="<?= $config['base_url'] ?>/categorias/create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Nueva categoría
    </a>
</div>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Indicador</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">No hay categorías registradas.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($items as $item): ?>
                <?php
                $esDefault = CategoriaModel::isDefault($item);
                $indicador = CategoriaModel::indicadorEfectivo($item['Nombre'] ?? '', $item['Indicador'] ?? null);
                ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($item['Nombre']) ?>
                        <?php if ($esDefault): ?>
                        <span class="badge bg-secondary ms-1">Por defecto</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($indicador === 'Consumible'): ?>
                        <span class="badge bg-warning text-dark">Consumible</span>
                        <?php elseif ($indicador === 'No Consumible'): ?>
                        <span class="badge bg-info">No Consumible</span>
                        <?php else: ?>
                        <span class="text-muted">Sin indicar</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $item['Estado'] === 'Activo' ? 'success' : 'secondary' ?>">
                            <?= htmlspecialchars($item['Estado']) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="<?= $config['base_url'] ?>/categorias/edit?id=<?= (int) $item['id_categoria'] ?>"
                           class="btn btn-sm btn-outline-primary" title="Editar">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php if (!$esDefault): ?>
                        <form method="POST" action="<?= $config['base_url'] ?>/categorias/delete"
                              class="d-inline" onsubmit="return confirm('¿Eliminar esta categoría?')">
                            <input type="hidden" name="id" value="<?= (int) $item['id_categoria'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
