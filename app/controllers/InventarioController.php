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

        $this->view('inventario/index', [
            'pageTitle'  => 'Inventario',
            'items'      => $this->model->getAll(
                $busqueda !== '' ? $busqueda : null,
                $categoriaId
            ),
            'categorias' => $this->categoriaModel->getActivas(),
            'busqueda'   => $busqueda,
            'categoriaId'=> $categoriaId,
            'flash'      => $this->getFlash(),
        ]);
    }

    public function create(): void
    {
        Auth::requireAdmin();
        $this->view('inventario/form', [
            'pageTitle'  => 'Nuevo elemento',
            'item'       => null,
            'action'     => 'store',
            'categorias' => $this->categoriaModel->getActivas(),
        ]);
    }

    public function store(): void
    {
        Auth::requireAdmin();
        $data = $this->getFormData();
        $data['cantidad'] = 0;
        $errors = $this->validate($data);

        if ($this->model->findByCodigo($data['codigo'])) {
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
            $this->view('inventario/form', [
                'pageTitle'  => 'Nuevo elemento',
                'item'       => $data,
                'action'     => 'store',
                'categorias' => $this->categoriaModel->getActivas(),
                'errors'     => $errors,
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
        $categorias = $this->categoriaModel->getActivas();
        $grouped = $this->model->getGroupedByCategoria();
        $saldos = $this->model->getSaldosIniciales();

        $nombres = [];
        foreach ($categorias as $cat) {
            $nombre = trim((string) ($cat['Nombre'] ?? 'General'));
            if ($nombre !== '') {
                $nombres[$nombre] = true;
            }
        }
        foreach (array_keys($grouped) as $nombre) {
            $nombres[$nombre] = true;
        }
        if (empty($nombres)) {
            $nombres['General'] = true;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=plantilla_inventario_categorias.csv');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fwrite($output, "# Plantilla de carga de inventario — Casa del Marqués\n");
        fwrite($output, "# Una sección por categoría. Los elementos que ya existen aparecen con Existe=Si, saldo inicial y stock actual.\n");
        fwrite($output, "# Agregue filas nuevas con Existe=No, Código, Elemento y SaldoInicial o Stock.\n");
        fwrite($output, "# Si el código ya existe no se duplica: se identifican y se conservan el stock y los movimientos.\n");
        fwrite($output, "# Estado permitido: Activo o Inactivo.\n");
        fwrite($output, "#\n");

        $headers = ['Codigo', 'Elemento', 'Descripcion', 'Estado', 'SaldoInicial', 'Stock', 'Existe'];

        foreach (array_keys($nombres) as $nombre) {
            fwrite($output, '# CATEGORIA: ' . $nombre . "\n");
            fputcsv($output, $headers, ';');

            foreach ($grouped[$nombre] ?? [] as $item) {
                $codigo = (string) ($item['Codigo'] ?? '');
                $stock = (int) ($item['Cantidad'] ?? 0);
                $saldo = $saldos[$codigo] ?? $stock;
                fputcsv($output, [
                    $codigo,
                    $item['Elemento'] ?? '',
                    $item['Descripcion'] ?? '',
                    $item['Estado'] ?? 'Activo',
                    $saldo,
                    $stock,
                    'Si',
                ], ';');
            }

            fputcsv($output, ['', '', '', 'Activo', '', '', 'No'], ';');
            fputcsv($output, ['', '', '', 'Activo', '', '', 'No'], ';');
            fwrite($output, "\n");
        }

        fclose($output);
        exit;
    }

    public function cargar(): void
    {
        Auth::requireAdmin();
        $this->view('inventario/cargar', [
            'pageTitle'  => 'Cargar inventario',
            'categorias' => $this->categoriaModel->getActivas(),
            'flash'      => $this->getFlash(),
            'errors'     => [],
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
            ]);
            return;
        }

        $msg = 'Carga finalizada: ' . $resultado['creados'] . ' nuevo(s)';
        if (!empty($resultado['existentes'])) {
            $msg .= ', ' . $resultado['existentes'] . ' ya existían (no se duplicaron, stock conservado)';
        }
        if (!empty($resultado['omitidos'])) {
            $msg .= '. Filas vacías omitidas: ' . $resultado['omitidos'];
        }
        $msg .= '.';

        $detalles = array_merge($resultado['existentes_detalle'] ?? [], $resultado['errores'] ?? []);
        $this->setFlash(
            ($resultado['errores'] || !empty($resultado['existentes'])) ? 'warning' : 'success',
            $msg,
            $detalles
        );
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
        $existentes = 0;
        $omitidos = 0;
        $errores = [];
        $existentesDetalle = [];
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            foreach (explode("\n", $content) as $i => $line) {
                $linea = $i + 1;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                if (str_starts_with($line, '#')) {
                    if (preg_match('/#\s*CATEGORIA\s*:\s*(.+)$/iu', $line, $m)) {
                        $categoriaActual = trim($m[1]);
                        $map = [];
                    }
                    continue;
                }

                $row = str_getcsv($line, $delimiter);
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

                $categoria = $this->categoriaModel->ensureByNombre($data['categoria_nombre']);
                if (!$categoria) {
                    $errores[] = 'Fila ' . $linea . ': no se pudo usar la categoría ' . $data['categoria_nombre'] . '.';
                    continue;
                }

                $existente = $this->model->findByCodigo($data['codigo']);
                if ($existente && ($existente['Estado'] ?? '') !== 'Eliminado') {
                    $stockActual = (int) $existente['Cantidad'];
                    $this->model->update($data['codigo'], [
                        'elemento'     => $data['elemento'],
                        'id_categoria' => (int) $categoria['id_categoria'],
                        'descripcion'  => $data['descripcion'],
                        'cantidad'     => $stockActual,
                        'fotografia'   => $existente['Fotografia'] ?? null,
                        'estado'       => $data['estado'],
                    ]);
                    $existentes++;
                    if (count($existentesDetalle) < 20) {
                        $existentesDetalle[] = $data['codigo'] . ' — ' . $data['elemento']
                            . ' ya existe (stock actual: ' . $stockActual
                            . ', saldo inicial en plantilla: '
                            . ($data['saldo_inicial'] ?? '—') . '). No se duplicó.';
                    }
                    continue;
                }

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
                } else {
                    $payload['codigo'] = $data['codigo'];
                    $this->model->create($payload);
                }
                $creados++;

                $homonimo = $this->model->findByElemento($data['elemento'], $data['codigo']);
                if ($homonimo && count($errores) < 20) {
                    $errores[] = 'Fila ' . $linea . ': se creó ' . $data['codigo']
                        . ', pero el nombre ya lo usa ' . $homonimo['Codigo'] . '.';
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return [
            'creados'             => $creados,
            'existentes'          => $existentes,
            'existentes_detalle'  => $existentesDetalle,
            'omitidos'            => $omitidos,
            'errores'             => $errores,
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
            $codigo = $get('codigo', 0);
            $elemento = $get('elemento', 1);
            $descripcion = $get('descripcion', 2);
            $estado = $get('estado', 3);
            $saldo = $get('saldo_inicial', 4);
            $stock = $get('stock', 5);
            $categoria = $get('categoria', -1);
        } else {
            $codigo = trim((string) ($row[0] ?? ''));
            $elemento = trim((string) ($row[1] ?? ''));
            $descripcion = trim((string) ($row[2] ?? ''));
            $estado = trim((string) ($row[3] ?? ''));
            $saldo = trim((string) ($row[4] ?? ''));
            $stock = trim((string) ($row[5] ?? ''));
            $categoria = '';
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
