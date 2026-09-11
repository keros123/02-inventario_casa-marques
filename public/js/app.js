window.InventarioCodigo = {
    incrementar: function (codigo) {
        const m = String(codigo || '').trim().match(/^([A-ZÑ]{1,3})(\d+)$/i);
        if (!m) {
            return codigo;
        }
        const n = parseInt(m[2], 10) + 1;
        return m[1].toUpperCase() + String(n).padStart(m[2].length, '0');
    },
    siguiente: function (inicial, reservados) {
        let codigo = String(inicial || '').trim();
        if (!codigo) {
            return '';
        }
        const used = (reservados || []).map(function (c) {
            return String(c).toUpperCase();
        });
        let guard = 0;
        while (codigo && used.indexOf(codigo.toUpperCase()) !== -1 && guard < 10000) {
            codigo = window.InventarioCodigo.incrementar(codigo);
            guard++;
        }
        return codigo;
    },
    deMapa: function (mapa, categoriaId, reservados) {
        if (!mapa) {
            return '';
        }
        const inicial = mapa[String(categoriaId)] || mapa[categoriaId] || '';
        return window.InventarioCodigo.siguiente(inicial, reservados);
    }
};

document.addEventListener('DOMContentLoaded', function () {
    const timezone = document.documentElement.getAttribute('data-timezone') || 'America/Bogota';
    window.APP_TIMEZONE = timezone;

    const currentPath = window.location.pathname;
    document.querySelectorAll('.app-sidebar .nav-link').forEach(function (link) {
        const href = link.getAttribute('href');
        if (href && currentPath.includes(href.replace(/\/$/, ''))) {
            link.classList.add('active');
        }
    });

    const fotoInput = document.getElementById('fotografiaInput');
    const fotoPreview = document.getElementById('fotoPreview');
    const fotoPlaceholder = document.getElementById('fotoPreviewPlaceholder');

    if (fotoInput && fotoPreview) {
        fotoInput.addEventListener('change', function () {
            const file = this.files[0];
            if (file && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    fotoPreview.src = e.target.result;
                    fotoPreview.hidden = false;
                    if (fotoPlaceholder) {
                        fotoPlaceholder.hidden = true;
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }

    const photoModal = document.getElementById('photoModal');
    const photoModalImage = document.getElementById('photoModalImage');

    if (photoModal && photoModalImage && typeof bootstrap !== 'undefined') {
        const modal = new bootstrap.Modal(photoModal);

        document.querySelectorAll('.photo-thumb-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                photoModalImage.src = this.dataset.photoSrc;
                photoModalImage.alt = this.dataset.photoTitle || 'Fotografía del elemento';
                modal.show();
            });
        });

        photoModal.addEventListener('hidden.bs.modal', function () {
            photoModalImage.src = '';
        });
    }

    const formInventario = document.getElementById('formInventario');
    if (formInventario && formInventario.dataset.edit !== '1') {
        const selectCategoria = formInventario.querySelector('[name="id_categoria"]');
        const inputCodigo = formInventario.querySelector('[name="codigo"]');
        let mapa = {};
        try {
            mapa = JSON.parse(formInventario.dataset.siguientes || '{}');
        } catch (e) {
            mapa = {};
        }
        if (selectCategoria && inputCodigo) {
            selectCategoria.addEventListener('change', function () {
                const sugerido = window.InventarioCodigo.deMapa(mapa, selectCategoria.value, []);
                if (sugerido) {
                    inputCodigo.value = sugerido;
                }
            });
        }
    }
});
