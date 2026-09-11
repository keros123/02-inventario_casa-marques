<?php
$isEdit = ($action ?? '') === 'update';
$esDefault = !empty($esDefault);
$val = function ($key, $default = '') use ($item) {
    if (is_array($item)) {
        return htmlspecialchars($item[$key] ?? $item[strtolower($key)] ?? $default);
    }
    return htmlspecialchars($default);
};
$indicadorActual = '';
if (is_array($item)) {
    $indicadorActual = (string) ($item['Indicador'] ?? $item['indicador'] ?? '');
}
?>

<div class="page-header">
    <h2 class="page-title"><?= $isEdit ? 'Editar categoría' : 'Nueva categoría' ?></h2>
    <p class="page-subtitle">
        <a href="<?= $config['base_url'] ?>/categorias" class="text-decoration-none">
            <i class="bi bi-arrow-left"></i> Volver al listado
        </a>
    </p>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="<?= $config['base_url'] ?>/categorias/<?= $isEdit ? 'update' : 'store' ?>" id="formCategoria">
            <?php if ($isEdit): ?>
            <input type="hidden" name="id_original" value="<?= $val('id_categoria') ?>">
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="nombre" id="nombreCategoria" class="form-control"
                           value="<?= $val('Nombre') ?>" <?= $esDefault ? 'readonly' : 'required' ?>>
                    <?php if ($esDefault): ?>
                    <div class="form-text">Categoría por defecto del sistema. No se puede renombrar ni eliminar.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4" id="wrapIndicador" <?= $esDefault ? 'hidden' : '' ?>>
                    <label class="form-label">Indicador *</label>
                    <select name="indicador" id="indicadorCategoria" class="form-select" <?= $esDefault ? 'disabled' : 'required' ?>>
                        <option value="">Seleccione…</option>
                        <option value="Consumible" <?= $indicadorActual === 'Consumible' ? 'selected' : '' ?>>Consumible</option>
                        <option value="No Consumible" <?= $indicadorActual === 'No Consumible' ? 'selected' : '' ?>>No Consumible</option>
                    </select>
                    <div class="form-text">Obligatorio en todas las categorías excepto General.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Estado</label>
                    <select name="estado" class="form-select">
                        <option value="Activo" <?= $val('Estado', 'Activo') === 'Activo' ? 'selected' : '' ?>>Activo</option>
                        <option value="Inactivo" <?= $val('Estado') === 'Inactivo' ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>
            </div>

            <?php if ($esDefault): ?>
            <p class="text-muted small mt-3 mb-0">
                La categoría <strong>General</strong> no usa el indicador Consumible / No Consumible.
            </p>
            <?php endif; ?>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Guardar
                </button>
                <a href="<?= $config['base_url'] ?>/categorias" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const nombre = document.getElementById('nombreCategoria');
    const wrap = document.getElementById('wrapIndicador');
    const indicador = document.getElementById('indicadorCategoria');
    const locked = <?= $esDefault ? 'true' : 'false' ?>;
    if (!nombre || !wrap || !indicador || locked) {
        return;
    }
    function toggle() {
        const esGeneral = nombre.value.trim().toLowerCase() === 'general';
        wrap.hidden = esGeneral;
        indicador.disabled = esGeneral;
        indicador.required = !esGeneral;
    }
    nombre.addEventListener('input', toggle);
    toggle();
});
</script>
