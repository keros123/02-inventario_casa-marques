<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h2 class="page-title">Cargar inventario</h2>
        <p class="page-subtitle">
            <a href="<?= $config['base_url'] ?>/inventario" class="text-decoration-none">
                <i class="bi bi-arrow-left"></i> Volver al inventario
            </a>
        </p>
    </div>
    <a href="<?= $config['base_url'] ?>/inventario/plantilla" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-download"></i> Descargar plantilla
    </a>
</div>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <?php if (!empty($flash['details'])): ?>
    <ul class="mb-0 mt-2">
        <?php foreach ($flash['details'] as $detalle): ?>
        <li><?= htmlspecialchars($detalle) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!empty($pendientes)): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        Hay <strong><?= count($pendientes) ?></strong> elemento(s) de una carga anterior que ya existían
        y siguen pendientes de acción.
    </div>
    <a href="<?= $config['base_url'] ?>/inventario/cargar/pendientes" class="btn btn-sm btn-warning">
        Revisar pendientes
    </a>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <h3 class="h6 mb-3">Subir archivo CSV</h3>
                <form method="POST" action="<?= $config['base_url'] ?>/inventario/cargar" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Archivo *</label>
                        <input type="file" name="archivo" class="form-control" accept=".csv,text/csv" required>
                        <div class="form-text">Máximo 2 MB. Use la plantilla: Categoria, Codigo, Elemento, Descripcion, Estado, SaldoInicial, Stock.</div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> Cargar inventario
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <h3 class="h6 mb-3">Cómo usarla</h3>
                <ol class="small mb-3 ps-3">
                    <li>Descargue la plantilla. Las columnas son <strong>Categoria, Codigo, Elemento, Descripcion, Estado, SaldoInicial, Stock</strong>.</li>
                    <li>Incluye los elementos actuales para que pueda comparar. Agregue filas nuevas o deje vacías las que no use.</li>
                    <li>Al cargar se verifica por <strong>código</strong> y por <strong>nombre de elemento</strong>.</li>
                    <li>Si ya existe, <strong>no se carga</strong>: queda pendiente para omitir, actualizar (sin cambiar el stock) o crear de todos modos.</li>
                    <li>Los elementos nuevos se importan de inmediato. Puede seguir con el resto aunque haya pendientes.</li>
                    <li>Una categoría nueva se crea con el nombre de la columna Categoria (sin indicador se muestra como «Sin indicar»).</li>
                </ol>
                <p class="small text-muted mb-2">Categorías actuales en la plantilla:</p>
                <?php if (empty($categorias)): ?>
                <p class="small mb-0">Solo <strong>General</strong> (no hay categorías activas).</p>
                <?php else: ?>
                <ul class="small mb-0">
                    <?php foreach ($categorias as $cat): ?>
                    <li><?= htmlspecialchars(CategoriaModel::formatLabel($cat['Nombre'] ?? '', $cat['Indicador'] ?? null)) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
