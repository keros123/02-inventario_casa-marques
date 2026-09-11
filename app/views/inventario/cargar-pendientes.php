<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h2 class="page-title">Elementos existentes en la carga</h2>
        <p class="page-subtitle">
            <a href="<?= $config['base_url'] ?>/inventario/cargar" class="text-decoration-none">
                <i class="bi bi-arrow-left"></i> Volver a cargar inventario
            </a>
        </p>
    </div>
    <form method="POST" action="<?= $config['base_url'] ?>/inventario/cargar/pendientes/omitir-todos"
          onsubmit="return confirm('¿Omitir todos los elementos pendientes?');">
        <button type="submit" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-x-circle"></i> Omitir todos
        </button>
    </form>
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

<p class="text-muted">
    Estos elementos no se cargaron porque el código o el nombre ya existen.
    El resto del archivo sí se importó. Tome una acción en cada fila.
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Fila</th>
                    <th>En el archivo</th>
                    <th>Ya existe en inventario</th>
                    <th>Motivo</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendientes as $item): ?>
                <?php
                    $data = $item['data'] ?? [];
                    $existente = $item['existente'] ?? [];
                    $puedeActualizar = !empty($item['puede_actualizar']);
                    $puedeCrear = !empty($item['puede_crear']);
                ?>
                <tr>
                    <td><?= (int) ($item['linea'] ?? 0) ?></td>
                    <td>
                        <div><strong><?= htmlspecialchars((string) ($data['codigo'] ?? '')) ?></strong></div>
                        <div><?= htmlspecialchars((string) ($data['elemento'] ?? '')) ?></div>
                        <div class="small text-muted">
                            <?= htmlspecialchars((string) ($data['categoria_nombre'] ?? '')) ?>
                            · <?= htmlspecialchars((string) ($data['estado'] ?? '')) ?>
                            · saldo <?= htmlspecialchars((string) ($data['saldo_inicial'] ?? '—')) ?>
                            · stock <?= htmlspecialchars((string) ($data['stock'] ?? '—')) ?>
                        </div>
                    </td>
                    <td>
                        <div><strong><?= htmlspecialchars((string) ($existente['Codigo'] ?? '')) ?></strong></div>
                        <div><?= htmlspecialchars((string) ($existente['Elemento'] ?? '')) ?></div>
                        <div class="small text-muted">
                            <?= htmlspecialchars((string) ($existente['Categoria'] ?? '')) ?>
                            · stock <?= (int) ($existente['Cantidad'] ?? 0) ?>
                        </div>
                    </td>
                    <td>
                        <ul class="small mb-0 ps-3">
                            <?php foreach (($item['motivos'] ?? []) as $motivo): ?>
                            <li><?= htmlspecialchars((string) $motivo) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                    <td class="text-end">
                        <div class="d-flex flex-wrap justify-content-end gap-1">
                            <form method="POST" action="<?= $config['base_url'] ?>/inventario/cargar/pendiente">
                                <input type="hidden" name="key" value="<?= htmlspecialchars((string) ($item['key'] ?? '')) ?>">
                                <input type="hidden" name="accion" value="omitir">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Omitir</button>
                            </form>
                            <?php if ($puedeActualizar): ?>
                            <form method="POST" action="<?= $config['base_url'] ?>/inventario/cargar/pendiente">
                                <input type="hidden" name="key" value="<?= htmlspecialchars((string) ($item['key'] ?? '')) ?>">
                                <input type="hidden" name="accion" value="actualizar">
                                <button type="submit" class="btn btn-sm btn-primary"
                                        title="Actualiza datos y conserva el stock actual">
                                    Actualizar
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($puedeCrear): ?>
                            <form method="POST" action="<?= $config['base_url'] ?>/inventario/cargar/pendiente">
                                <input type="hidden" name="key" value="<?= htmlspecialchars((string) ($item['key'] ?? '')) ?>">
                                <input type="hidden" name="accion" value="crear">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    Crear de todos modos
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
