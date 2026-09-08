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

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <h3 class="h6 mb-3">Subir archivo CSV</h3>
                <form method="POST" action="<?= $config['base_url'] ?>/inventario/cargar" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Archivo *</label>
                        <input type="file" name="archivo" class="form-control" accept=".csv,text/csv" required>
                        <div class="form-text">Máximo 2 MB. Use la plantilla descargada y complete una sección por categoría.</div>
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
                    <li>Descargue la plantilla. Trae cada categoría, los elementos que ya existen, su <strong>saldo inicial</strong> y el <strong>stock</strong>.</li>
                    <li>La columna <strong>Existe</strong> indica <em>Si</em> si el código ya está en el inventario.</li>
                    <li>Para elementos nuevos complete Código, Elemento y <strong>SaldoInicial</strong> o <strong>Stock</strong> (Existe = No).</li>
                    <li>Si el código ya existe no se duplica: se identifican, se actualizan los datos y se conserva el stock.</li>
                    <li>Deje vacías las filas que no vaya a usar.</li>
                </ol>
                <p class="small text-muted mb-2">Categorías actuales en la plantilla:</p>
                <?php if (empty($categorias)): ?>
                <p class="small mb-0">Solo <strong>General</strong> (no hay categorías activas).</p>
                <?php else: ?>
                <ul class="small mb-0">
                    <?php foreach ($categorias as $cat): ?>
                    <li><?= htmlspecialchars($cat['Nombre']) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
