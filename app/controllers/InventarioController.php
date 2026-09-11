<?php

require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Storage.php';
require_once __DIR__ . '/../models/InventarioModel.php';
require_once __DIR__ . '/../models/CategoriaModel.php';

class InventarioController extends Controller
{
    private InventarioModel $model;
    private CategoriaModel $categoriaModel;

    public function __construct()
    {
        parent::__construct();
        Auth::requireLogin();
        $this->model = new InventarioModel();
        $this->categoriaModel = new CategoriaModel();
    }

    public function index(): void
    {
        $busqueda = trim($_GET['q'] ?? '');
        $categoriaId = (int) ($_GET['categoria'] ?? 0) ?: null;
        $filtroBusqueda = $busqueda !== '' ? $busqueda : null;
        $perPage = 20;
        $total = $this->model->countAll($filtroBusqueda, $categoriaId);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $items = $this->model->getAll($filtroBusqueda, $categoriaId, $perPage, $offset);
        $from = $total === 0 ? 0 : $offset + 1;
        $to = $total === 0 ? 0 : $offset + count($items);

        $this->view('inventario/index', [
            'pageTitle'  => 'Inventario',
            'items'      => $items,
            'categorias' => $this->categoriaModel->getActivas(),
            'busqueda'   => $busqueda,
            'categoriaId'=> $categoriaId,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
            'from'       => $from,
            'to'         => $to,
            'flash'      => $this->getFlash(),
        ]);
    }

    public function create(): void
    {
        Auth::requireAdmin();
        $categorias = $this->categoriaModel->getActivas();
        $this->view('inventario/form', [
            'pageTitle'               => 'Nuevo elemento',
            'item'                    => null,
            'action'                  => 'store',
            'categorias'              => $categorias,
            'siguientesPorCategoria'  => $this->model->getSiguientesCodigos($categorias),
        ]);
    }

    public function siguienteCodigo(): void
    {
        Auth::requireLogin();
        $categoriaId = (int) ($_GET['categoria'] ?? 0);
        $categoria = $categoriaId > 0 ? $this->categoriaModel->findById($categoriaId) : null;
        if (!$categoria) {
            $this->json(['error' => 'Categoría no válida.'], 400);
        }

        $reservados = array_filter(array_map('trim', explode(',', (string) ($_GET['reservados'] ?? ''))));
        $codigo = $this->model->suggestNextCodigo(
            $categoriaId,
            (string) ($categoria['Nombre'] ?? ''),
            $reservados
        );

        $this->json([
            'codigo'      => $codigo,
            'letra'       => InventarioModel::prefijoCategoria((string) ($categoria['Nombre'] ?? '')),
            'prefijo'     => InventarioModel::prefijoCategoria((string) ($categoria['Nombre'] ?? '')),
            'categoria'   => (int) $categoria['id_categoria'],
        ]);
    }

    public function store(): void
    {
        Auth::requireAdmin();
        $data = $this->getFormData();
        $data['cantidad'] = 0;
        if ($data['codigo'] === '' && !empty($data['id_categoria'])) {
            $categoria = $this->categoriaModel->findById((int) $data['id_categoria']);
            if ($categoria) {
                $data['codigo'] = $this->model->suggestNextCodigo(
                    (int) $categoria['id_categoria'],
                    (string) ($categoria['Nombre'] ?? '')
                );
            }
        }
        $errors = $this->validate($data);

        if ($data['codigo'] !== '' && $this->model->findByCodigo($data['codigo'])) {
            $errors[] = 'El código ya existe.';
        }

        if (!$errors) {
            try {
                $data['fotografia'] = $this->handleUpload($data['codigo']);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors) {
            $categorias = $this->categoriaModel->getActivas();
            $this->view('inventario/form', [
                'pageTitle'              => 'Nuevo elemento',
                'item'                   => $data,
                'action'                 => 'store',
                'categorias'             => $categorias,
                'siguientesPorCategoria' => $this->model->getSiguientesCodigos($categorias),
                'errors'                 => $errors,
            ]);
            return;
        }

        $this->model->create($data);
        $this->setFlash('success', 'Elemento registrado correctamente.');
        $this->redirect('/inventario');
    }

    public function edit(): void
    {
        Auth::requireAdmin();
        $codigo = $_GET['codigo'] ?? '';
        $item = $this->model->findByCodigo($codigo);

        if (!$item) {
            $this->setFlash('danger', 'Elemento no encontrado.');
            $this->redirect('/inventario');
        }

        $this->view('inventario/form', [
            'pageTitle'  => 'Editar elemento',
            'item'       => $item,
            'action'     => 'update',
            'categorias' => $this->categoriaModel->getActivas(),
        ]);
    }

    public function update(): void
    {
        Auth::requireAdmin();
        $codigo = $_POST['codigo_original'] ?? '';
        $item = $this->model->findByCodigo($codigo);

        if (!$item) {
            $this->setFlash('danger', 'Elemento no encontrado.');
            $this->redirect('/inventario');
        }

        $data = $this->getFormData();
        $errors = $this->validate($data, false);
        $foto = null;

        if (!$errors) {
            try {
                $foto = $this->handleUpload($codigo);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors) {
            $data['Codigo'] = $codigo;
            $this->view('inventario/form', [
                'pageTitle'  => 'Editar elemento',
                'item'       => array_merge($item, $data),
                'action'     => 'update',
                'categorias' => $this->categoriaModel->getActivas(),
                'errors'     => $errors,
            ]);
            return;
        }

        $data['fotografia'] = $foto ?: $item['Fotografia'];
        $data['cantidad'] = (int) $item['Cantidad'];
        $this->model->update($codigo, $data);
        $this->setFlash('success', 'Elemento actualizado correctamente.');
        $this->redirect('/inventario');
    }

    public function delete(): void
    {
        Auth::requireAdmin();
        $codigo = $_POST['codigo'] ?? '';
        if ($codigo) {
            $this->model->delete($codigo);
            $this->setFlash('success', 'Elemento eliminado.');
        }
        $this->redirect('/inventario');
    }

    public function exportCsv(): void
    {
        $busqueda = trim($_GET['q'] ?? '');
        $categoriaId = (int) ($_GET['categoria'] ?? 0) ?: null;
        $items = $this->model->getAll($busqueda !== '' ? $busqueda : null, $categoriaId);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=inventario_' . date('YmdHis') . '.csv');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Código', 'Elemento', 'Categoría', 'Descripción', 'Cantidad', 'Estado']);

        foreach ($items as $item) {
            fputcsv($output, [
                $item['Codigo'],
                $item['Elemento'],
                $item['Categoria'] ?? '—',
                $item['Descripcion'],
                (int) $item['Cantidad'],
                $item['Estado'],
            ]);
        }

        fclose($output);
        exit;
    }

    public function plantilla(): void
    {
        Auth::requireAdmin();
        $items = $this->model->getAll();
        $saldos = $this->model->getSaldosIniciales();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=plantilla_inventario.csv');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headers = ['Categoria', 'Codigo', 'Elemento', 'Descripcion', 'Estado', 'SaldoInicial', 'Stock'];
        fputcsv($output, $headers, ';');

        foreach ($items as $item) {
            $codigo = (string) ($item['Codigo'] ?? '');
            $stock = (int) ($item['Cantidad'] ?? 0);
            $saldo = $saldos[$codigo] ?? $stock;
            fputcsv($output, [
                $item['Categoria'] ?? 'General',
                $codigo,
                $item['Elemento'] ?? '',
                $item['Descripcion'] ?? '',
                $item['Estado'] ?? 'Activo',
                $saldo,
                $stock,
            ], ';');
        }

        for ($i = 0; $i < 8; $i++) {
            fputcsv($output, ['', '', '', '', '', '', ''], ';');
        }

        fclose($output);
        exit;
    }

    public function cargar(): void
    {
        Auth::requireAdmin();
        $this->view('inventario/cargar', [
            'pageTitle'   => 'Cargar inventario',
            'categorias'  => $this->categoriaModel->getActivas(),
            'flash'       => $this->getFlash(),
            'errors'      => [],
            'pendientes'  => $this->getCargaPendientes(),
        ]);
    }

    public function cargarStore(): void
    {
        Auth::requireAdmin();

        $file = $_FILES['archivo'] ?? null;
        $errors = [];

        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Seleccione un archivo CSV.';
        } elseif ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'No se pudo subir el archivo.';
        } else {
            $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'], true)) {
                $errors[] = 'El archivo debe ser CSV.';
            }
            if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
                $errors[] = 'El archivo no puede superar 2 MB.';
            }
        }

        if ($errors) {
            $this->view('inventario/cargar', [
                'pageTitle'  => 'Cargar inventario',
                'categorias' => $this->categoriaModel->getActivas(),
                'flash'      => null,
                'errors'     => $errors,
                'pendientes' => $this->getCargaPendientes(),
            ]);
            return;
        }

        try {
            $resultado = $this->importarCsv((string) $file['tmp_name']);
        } catch (Throwable $e) {
            error_log('InventarioController::cargarStore — ' . $e->getMessage());
            $this->view('inventario/cargar', [
                'pageTitle'  => 'Cargar inventario',
                'categorias' => $this->categoriaModel->getActivas(),
                'flash'      => null,
                'errors'     => ['No se pudo procesar el archivo. Verifique que corresponda a la plantilla.'],
                'pendientes' => $this->getCargaPendientes(),
            ]);
            return;
        }

        $this->setCargaPendientes($resultado['conflictos'] ?? []);

        $msg = 'Carga finalizada: ' . $resultado['creados'] . ' nuevo(s)';
        if (!empty($resultado['categorias_nuevas'])) {
            $msg .= ', categorías creadas: ' . implode(', ', $resultado['categorias_nuevas']);
        }
        if (!empty($resultado['conflictos'])) {
            $msg .= ', ' . count($resultado['conflictos']) . ' ya existían y quedaron pendientes de acción';
        }
        if (!empty($resultado['omitidos'])) {
            $msg .= '. Filas vacías omitidas: ' . $resultado['omitidos'];
        }
        $msg .= '.';

        $this->setFlash(
            ($resultado['errores'] || !empty($resultado['conflictos'])) ? 'warning' : 'success',
            $msg,
            $resultado['errores'] ?? []
        );

        if (!empty($resultado['conflictos'])) {
            $this->redirect('/inventario/cargar/pendientes');
        }
        $this->redirect('/inventario/cargar');
    }

    public function cargarPendientes(): void
    {
        Auth::requireAdmin();
        $pendientes = $this->getCargaPendientes();
        if (empty($pendientes)) {
            $this->setFlash('info', 'No hay elementos pendientes de la última carga.');
            $this->redirect('/inventario/cargar');
        }

        $this->view('inventario/cargar-pendientes', [
            'pageTitle'  => 'Elementos existentes en la carga',
            'pendientes' => $pendientes,
            'flash'      => $this->getFlash(),
        ]);
    }

    public function cargarPendienteAccion(): void
    {
        Auth::requireAdmin();
        $key = (string) ($_POST['key'] ?? '');
        $accion = (string) ($_POST['accion'] ?? '');
        $pendientes = $this->getCargaPendientes();
        $item = $pendientes[$key] ?? null;

        if (!$item) {
            $this->setFlash('danger', 'Ese elemento ya no está en la lista de pendientes.');
            $this->redirect('/inventario/cargar/pendientes');
        }

        try {
            if ($accion === 'actualizar') {
                $this->aplicarPendienteActualizar($item);
                $this->setFlash('success', 'Se actualizó ' . $item['data']['codigo'] . ' (el stock no cambió).');
            } elseif ($accion === 'crear') {
                $this->aplicarPendienteCrear($item);
                $this->setFlash('success', 'Se creó ' . $item['data']['codigo'] . ' aunque el nombre ya existía.');
            } elseif ($accion === 'omitir') {
                $this->setFlash('info', 'Se omitió ' . $item['data']['codigo'] . '.');
            } else {
                $this->setFlash('danger', 'Acción no válida.');
                $this->redirect('/inventario/cargar/pendientes');
            }
        } catch (RuntimeException $e) {
            $this->setFlash('danger', $e->getMessage());
            $this->redirect('/inventario/cargar/pendientes');
        }

        unset($pendientes[$key]);
        $this->setCargaPendientes($pendientes);

        if (empty($pendientes)) {
            $this->redirect('/inventario/cargar');
        }
        $this->redirect('/inventario/cargar/pendientes');
    }

    public function cargarPendientesOmitirTodos(): void
    {
        Auth::requireAdmin();
        $this->setCargaPendientes([]);
        $this->setFlash('info', 'Se omitieron todos los elementos pendientes.');
        $this->redirect('/inventario/cargar');
    }

    private function importarCsv(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('No se pudo leer el CSV.');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $delimiter = substr_count($content, ';') >= substr_count($content, ',') ? ';' : ',';
        $categoriaActual = 'General';
        $map = [];
        $creados = 0;
        $omitidos = 0;
        $errores = [];
        $conflictos = [];
        $categoriasNuevas = [];
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            foreach (explode("\n", $content) as $i => $line) {
                $linea = $i + 1;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = str_getcsv($line, $delimiter);
                $first = trim((string) ($row[0] ?? ''));
                if (str_starts_with($line, '#') || str_starts_with($first, '#')) {
                    $marcador = $this->parseCategoriaMarcador($line, $row);
                    if ($marcador !== null) {
                        $categoriaActual = $marcador['nombre'];
                        $map = [];
                    }
                    continue;
                }

                $normalized = array_map(fn ($v) => $this->normalizeHeader((string) $v), $row);
                if ($this->isHeaderRow($normalized)) {
                    $map = $this->headerMap($normalized);
                    continue;
                }

                $data = $this->rowToItem($row, $map, $categoriaActual);
                if ($data === null) {
                    $omitidos++;
                    continue;
                }

                if ($data['elemento'] === '') {
                    $errores[] = 'Fila ' . $linea . ': el nombre del elemento es obligatorio (' . $data['codigo'] . ').';
                    continue;
                }

                $nombreCat = $data['categoria_nombre'];
                if (strcasecmp($nombreCat, 'NuevaCategoria') === 0) {
                    $errores[] = 'Fila ' . $linea . ': cambie "NuevaCategoria" por el nombre real de la categoría.';
                    continue;
                }

                $porCodigo = $this->model->findByCodigo($data['codigo']);
                if ($porCodigo && ($porCodigo['Estado'] ?? '') === 'Eliminado') {
                    $porCodigo = null;
                }
                $porElemento = $this->model->findByElemento($data['elemento'], $data['codigo']);

                $motivos = [];
                if ($porCodigo) {
                    $motivos[] = 'El código ' . $data['codigo'] . ' ya existe (' . ($porCodigo['Elemento'] ?? '') . ').';
                }
                if ($porElemento) {
                    $motivos[] = 'El elemento "' . $data['elemento'] . '" ya existe con el código '
                        . ($porElemento['Codigo'] ?? '') . '.';
                }

                if ($motivos) {
                    $ref = $porCodigo ?: $porElemento;
                    $key = $data['codigo'] . ':' . $linea;
                    $conflictos[$key] = [
                        'key'              => $key,
                        'linea'            => $linea,
                        'motivos'          => $motivos,
                        'data'             => $data,
                        'existente'        => $this->snapshotExistente($ref),
                        'puede_actualizar' => $porCodigo !== null,
                        'puede_crear'      => $porCodigo === null,
                    ];
                    continue;
                }

                try {
                    $yaExisteCat = (bool) $this->categoriaModel->findByNombre($nombreCat);
                    $this->crearDesdeCarga($data);
                    $creados++;
                    if (!$yaExisteCat && $this->categoriaModel->findByNombre($nombreCat)) {
                        $categoriasNuevas[$nombreCat] = $nombreCat;
                    }
                } catch (RuntimeException $e) {
                    $errores[] = 'Fila ' . $linea . ': ' . $e->getMessage();
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return [
            'creados'           => $creados,
            'conflictos'        => $conflictos,
            'categorias_nuevas' => array_values($categoriasNuevas),
            'omitidos'          => $omitidos,
            'errores'           => $errores,
        ];
    }

    private function snapshotExistente(?array $row): array
    {
        if (!$row) {
            return [];
        }

        return [
            'Codigo'    => (string) ($row['Codigo'] ?? ''),
            'Elemento'  => (string) ($row['Elemento'] ?? ''),
            'Categoria' => (string) ($row['Categoria'] ?? ''),
            'Cantidad'  => (int) ($row['Cantidad'] ?? 0),
            'Estado'    => (string) ($row['Estado'] ?? ''),
        ];
    }

    private function crearDesdeCarga(array $data): void
    {
        $nombreCat = trim((string) ($data['categoria_nombre'] ?? '')) ?: 'General';
        $categoria = $this->categoriaModel->ensureByNombre($nombreCat);
        if (!$categoria) {
            throw new RuntimeException('No se pudo usar la categoría ' . $nombreCat . '.');
        }

        $existente = $this->model->findByCodigo($data['codigo']);
        $cantidad = $data['stock'] ?? $data['saldo_inicial'] ?? 0;
        $payload = [
            'elemento'     => $data['elemento'],
            'id_categoria' => (int) $categoria['id_categoria'],
            'descripcion'  => $data['descripcion'],
            'cantidad'     => $cantidad,
            'fotografia'   => $existente['Fotografia'] ?? null,
            'estado'       => $data['estado'],
        ];

        if ($existente) {
            $this->model->update($data['codigo'], $payload);
            return;
        }

        $payload['codigo'] = $data['codigo'];
        $this->model->create($payload);
    }

    private function aplicarPendienteActualizar(array $item): void
    {
        $data = $item['data'] ?? [];
        $existente = $this->model->findByCodigo((string) ($data['codigo'] ?? ''));
        if (!$existente || ($existente['Estado'] ?? '') === 'Eliminado') {
            throw new RuntimeException('El código ya no existe. Use «Crear de todos modos» u omita la fila.');
        }

        $nombreCat = trim((string) ($data['categoria_nombre'] ?? '')) ?: 'General';
        $categoria = $this->categoriaModel->ensureByNombre($nombreCat);
        if (!$categoria) {
            throw new RuntimeException('No se pudo usar la categoría ' . $nombreCat . '.');
        }

        $this->model->update($data['codigo'], [
            'elemento'     => $data['elemento'],
            'id_categoria' => (int) $categoria['id_categoria'],
            'descripcion'  => $data['descripcion'],
            'cantidad'     => (int) $existente['Cantidad'],
            'fotografia'   => $existente['Fotografia'] ?? null,
            'estado'       => $data['estado'],
        ]);
    }

    private function aplicarPendienteCrear(array $item): void
    {
        $data = $item['data'] ?? [];
        $existente = $this->model->findByCodigo((string) ($data['codigo'] ?? ''));
        if ($existente && ($existente['Estado'] ?? '') !== 'Eliminado') {
            throw new RuntimeException('El código ya existe. Use Actualizar u Omitir.');
        }

        $this->crearDesdeCarga($data);
    }

    private function getCargaPendientes(): array
    {
        Auth::startSession();
        return $_SESSION['carga_pendiente'] ?? [];
    }

    private function setCargaPendientes(array $pendientes): void
    {
        Auth::startSession();
        $_SESSION['carga_pendiente'] = $pendientes;
    }

    private function parseCategoriaMarcador(string $line, array $row): ?array
    {
        $first = trim((string) ($row[0] ?? ''));
        if (!preg_match('/#\s*CATEGORIA\b/iu', $first) && !preg_match('/#\s*CATEGORIA\b/iu', $line)) {
            return null;
        }

        $nombre = isset($row[1]) ? trim((string) $row[1]) : '';
        $indicadorRaw = isset($row[2]) ? trim((string) $row[2]) : '';

        if ($nombre === '' && preg_match('/#\s*CATEGORIA\s*:?\s*(.+)$/iu', $line, $m)) {
            $nombre = trim($m[1], " \t\"';:");
        }

        $nombre = trim($nombre, " \t\"';:");
        if ($nombre === '' || preg_match('/^codigo$/iu', $this->normalizeHeader($nombre))) {
            return null;
        }

        return [
            'nombre'    => $nombre,
            'indicador' => CategoriaModel::normalizeIndicador($indicadorRaw),
        ];
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
        return preg_replace('/[\s_]+/', '', $value) ?? $value;
    }

    private function isHeaderRow(array $normalized): bool
    {
        return in_array('codigo', $normalized, true) && in_array('elemento', $normalized, true);
    }

    private function headerMap(array $normalized): array
    {
        $aliases = [
            'categoria'     => 'categoria',
            'codigo'        => 'codigo',
            'elemento'      => 'elemento',
            'descripcion'   => 'descripcion',
            'estado'        => 'estado',
            'saldoinicial'  => 'saldo_inicial',
            'saldo'         => 'saldo_inicial',
            'stock'         => 'stock',
            'cantidad'      => 'stock',
            'existe'        => 'existe',
            'yaexiste'      => 'existe',
        ];
        $map = [];
        foreach ($normalized as $i => $name) {
            if (isset($aliases[$name])) {
                $map[$aliases[$name]] = $i;
            }
        }
        return $map;
    }

    private function parseEntero(?string $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || strcasecmp($value, 'si') === 0 || strcasecmp($value, 'no') === 0) {
            return null;
        }
        $value = str_replace([' ', '.'], '', $value);
        $value = str_replace(',', '.', $value);
        if (!is_numeric($value)) {
            return null;
        }
        return max(0, (int) round((float) $value));
    }

    private function rowToItem(array $row, array $map, string $categoriaSeccion): ?array
    {
        $get = static function (string $key, int $fallback) use ($row, $map): string {
            $i = $map[$key] ?? $fallback;
            if ($i < 0) {
                return '';
            }
            return trim((string) ($row[$i] ?? ''));
        };

        if ($map) {
            $categoria = $get('categoria', -1);
            $codigo = $get('codigo', 1);
            $elemento = $get('elemento', 2);
            $descripcion = $get('descripcion', 3);
            $estado = $get('estado', 4);
            $saldo = $get('saldo_inicial', 5);
            $stock = $get('stock', 6);
        } else {
            $categoria = trim((string) ($row[0] ?? ''));
            $codigo = trim((string) ($row[1] ?? ''));
            $elemento = trim((string) ($row[2] ?? ''));
            $descripcion = trim((string) ($row[3] ?? ''));
            $estado = trim((string) ($row[4] ?? ''));
            $saldo = trim((string) ($row[5] ?? ''));
            $stock = trim((string) ($row[6] ?? ''));
        }

        if ($codigo === '') {
            return null;
        }

        if (!in_array($estado, ['Activo', 'Inactivo'], true)) {
            $estado = 'Activo';
        }

        $categoriaNombre = $categoria !== '' ? $categoria : $categoriaSeccion;
        if ($categoriaNombre === '') {
            $categoriaNombre = 'General';
        }

        return [
            'codigo'           => $codigo,
            'elemento'         => $elemento,
            'descripcion'      => $descripcion,
            'estado'           => $estado,
            'categoria_nombre' => $categoriaNombre,
            'saldo_inicial'    => $this->parseEntero($saldo),
            'stock'            => $this->parseEntero($stock),
        ];
    }

    private function getFormData(): array
    {
        return [
            'codigo'       => trim($_POST['codigo'] ?? ''),
            'elemento'     => trim($_POST['elemento'] ?? ''),
            'id_categoria' => (int) ($_POST['id_categoria'] ?? 0) ?: $this->categoriaModel->getDefaultId(),
            'descripcion'  => trim($_POST['descripcion'] ?? ''),
            'cantidad'     => (int) ($_POST['cantidad'] ?? 0),
            'estado'       => $_POST['estado'] ?? 'Activo',
        ];
    }

    private function validate(array $data, bool $requireCodigo = true): array
    {
        $errors = [];
        if ($requireCodigo && $data['codigo'] === '') {
            $errors[] = 'El código es obligatorio.';
        }
        if ($data['elemento'] === '') {
            $errors[] = 'El nombre del elemento es obligatorio.';
        }
        if (empty($data['id_categoria'])) {
            $errors[] = 'Seleccione una categoría.';
        }
        if ($data['cantidad'] < 0) {
            $errors[] = 'La cantidad no puede ser negativa.';
        }
        return $errors;
    }

    private function handleUpload(string $codigo): ?string
    {
        if (empty($_FILES['fotografia']['name'])) {
            return null;
        }

        if (($_FILES['fotografia']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo subir la fotografía.');
        }

        $ext = strtolower(pathinfo((string) $_FILES['fotografia']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            throw new RuntimeException('Formato de imagen no permitido. Use jpg, png, gif o webp.');
        }

        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $codigo) . '.' . $ext;
        $mime = self::mimeFromExtension($ext);
        $tmp = (string) $_FILES['fotografia']['tmp_name'];

        return Storage::uploadFile($tmp, 'inventario/' . $filename, $mime);
    }

    private static function mimeFromExtension(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'application/octet-stream',
        };
    }
}
