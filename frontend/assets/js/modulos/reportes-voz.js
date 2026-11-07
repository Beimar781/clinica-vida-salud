/* P6 Reportes y Bitácora · CU-18: asistente de voz de «Reportes» (extra aprobado por el equipo, no está en el documento v30).
 *
 * Qué hace: entiende una frase escrita o dictada («citas por médico del 1 al 31 de octubre con IA»), llena el formulario, genera el reporte
 * y lee en voz alta SOLO los totales. La interpretación es local (reglas en este archivo): el texto de la frase no se envía a la IA ni al
 * servidor; solo se envían los filtros del formulario, como si la persona los hubiera elegido a mano. El dictado usa el reconocimiento de
 * voz del navegador (Chrome/Edge), que envía el audio al servicio del fabricante: por eso la pantalla pide no decir nombres de pacientes.
 * Sin Node, sin CDN: solo APIs del navegador (SpeechRecognition y speechSynthesis); si no existen, queda el campo de texto.
 */
(function (raiz) {
    'use strict';

    var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    var RE_MES = '(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)';
    var NUMEROS = {
        uno: 1, una: 1, primero: 1, dos: 2, tres: 3, cuatro: 4, cinco: 5, seis: 6, siete: 7, ocho: 8, nueve: 9, diez: 10, once: 11, doce: 12,
        trece: 13, catorce: 14, quince: 15, dieciseis: 16, diecisiete: 17, dieciocho: 18, diecinueve: 19, veinte: 20, veintiuno: 21, veintiun: 21,
        veintidos: 22, veintitres: 23, veinticuatro: 24, veinticinco: 25, veintiseis: 26, veintisiete: 27, veintiocho: 28, veintinueve: 29, treinta: 30
    };
    var ESTADOS = [
        ['PROGRAMADA', /\bprogramad/], ['CONFIRMADA', /\bconfirmad/], ['ATENDIDA', /\batendid/], ['CANCELADA', /\bcancelad/], ['INASISTENCIA', /\binasistenc/]
    ];

    /** Minúsculas, sin tildes ni signos: «¿Citas de mañana?» -> «citas de manana». */
    function normalizar(texto) {
        return String(texto || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9\/ ]+/g, ' ').replace(/\s+/g, ' ').trim();
    }

    /** «del uno al treinta y uno de octubre» -> «del 1 al 31 de octubre». */
    function numeros(texto) {
        return texto.replace(/\btreinta y uno\b/g, '31').replace(/\b[a-z]+\b/g, function (palabra) {
            return Object.prototype.hasOwnProperty.call(NUMEROS, palabra) ? String(NUMEROS[palabra]) : palabra;
        });
    }

    function dos(n) { return (n < 10 ? '0' : '') + n; }
    function iso(d) { return d.getFullYear() + '-' + dos(d.getMonth() + 1) + '-' + dos(d.getDate()); }
    function sumarDias(d, n) { var r = new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); return r; }
    function mesNumero(nombre) { return nombre === 'setiembre' ? 8 : MESES.indexOf(nombre); }

    /** Fecha válida (rechaza «31 de febrero») o null. */
    function fecha(anio, mes, dia) {
        var d = new Date(anio, mes, dia);
        return (d.getFullYear() === anio && d.getMonth() === mes && d.getDate() === dia) ? d : null;
    }

    /** {desde, hasta, texto} a partir de la frase con los números ya convertidos, o null si no hay fechas. */
    function fechas(nn, hoy) {
        var m, a, d1, d2;
        var anioPor = function (texto) { return texto ? parseInt(texto, 10) : hoy.getFullYear(); };
        var rango = function (d, h, texto) { return (d && h) ? { desde: iso(d), hasta: iso(h), texto: texto } : { error: 'La fecha dicha no existe en el calendario.' }; };

        // «del 10 de octubre al 15 de noviembre [de 2026]»
        m = nn.match(new RegExp('\\bdel (\\d{1,2}) de ' + RE_MES + ' al (\\d{1,2}) de ' + RE_MES + '(?: de (\\d{4}))?'));
        if (m) { a = anioPor(m[5]); return rango(fecha(a, mesNumero(m[2]), +m[1]), fecha(a, mesNumero(m[4]), +m[3]), 'del ' + m[1] + ' de ' + m[2] + ' al ' + m[3] + ' de ' + m[4]); }
        // «del 1 al 31 de octubre [de 2026]»
        m = nn.match(new RegExp('\\bdel (\\d{1,2}) al (\\d{1,2}) de ' + RE_MES + '(?: de (\\d{4}))?'));
        if (m) { a = anioPor(m[4]); return rango(fecha(a, mesNumero(m[3]), +m[1]), fecha(a, mesNumero(m[3]), +m[2]), 'del ' + m[1] + ' al ' + m[2] + ' de ' + m[3]); }
        // «del 01/10/2026 al 31/10/2026» y «el 10/11/2026»
        m = nn.match(/\bdel (\d{1,2})\/(\d{1,2})\/(\d{4}) al (\d{1,2})\/(\d{1,2})\/(\d{4})/);
        if (m) { return rango(fecha(+m[3], +m[2] - 1, +m[1]), fecha(+m[6], +m[5] - 1, +m[4]), 'del ' + m[1] + '/' + m[2] + '/' + m[3] + ' al ' + m[4] + '/' + m[5] + '/' + m[6]); }
        m = nn.match(/\b(\d{1,2})\/(\d{1,2})\/(\d{4})/);
        if (m) { d1 = fecha(+m[3], +m[2] - 1, +m[1]); return rango(d1, d1, 'el ' + m[0]); }

        // Relativas
        if (/\bpasado manana\b/.test(nn)) { return rango(sumarDias(hoy, 2), sumarDias(hoy, 2), 'pasado mañana'); }
        if (/\bmanana\b/.test(nn)) { return rango(sumarDias(hoy, 1), sumarDias(hoy, 1), 'mañana'); }
        if (/\bayer\b/.test(nn)) { return rango(sumarDias(hoy, -1), sumarDias(hoy, -1), 'ayer'); }
        if (/\bhoy\b/.test(nn)) { return rango(hoy, hoy, 'hoy'); }
        var lunes = sumarDias(hoy, -((hoy.getDay() + 6) % 7));
        if (/\bsemana pasada\b/.test(nn)) { return rango(sumarDias(lunes, -7), sumarDias(lunes, -1), 'la semana pasada'); }
        if (/\b(esta|la) semana\b/.test(nn)) { return rango(lunes, sumarDias(lunes, 6), 'esta semana'); }
        if (/\bmes pasado\b/.test(nn)) {
            d1 = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1);
            return rango(d1, new Date(hoy.getFullYear(), hoy.getMonth(), 0), 'el mes pasado');
        }
        if (/\b(este|el) mes\b/.test(nn)) { return rango(new Date(hoy.getFullYear(), hoy.getMonth(), 1), new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0), 'este mes'); }
        if (/\beste ano\b/.test(nn)) { return rango(new Date(hoy.getFullYear(), 0, 1), new Date(hoy.getFullYear(), 11, 31), 'este año'); }

        // «el 10 de noviembre [de 2026]»
        m = nn.match(new RegExp('\\b(\\d{1,2}) de ' + RE_MES + '(?: de (\\d{4}))?'));
        if (m) { d1 = fecha(anioPor(m[3]), mesNumero(m[2]), +m[1]); return rango(d1, d1, 'el ' + m[1] + ' de ' + m[2]); }
        // «octubre [de 2026]» = el mes completo
        m = nn.match(new RegExp('\\b' + RE_MES + '(?: de (\\d{4}))?'));
        if (m) {
            a = anioPor(m[2]);
            return rango(fecha(a, mesNumero(m[1]), 1), new Date(a, mesNumero(m[1]) + 1, 0), 'de ' + m[1]);
        }

        return null;
    }

    /** Puntos de coincidencia entre una frase y un nombre (cuántas palabras del nombre dijo la persona). */
    function coincidencias(frase, nombre) {
        var palabras = normalizar(nombre.replace(/·.*$/, '')).split(' ').filter(function (p) { return p.length >= 3 && p !== 'dra' && p !== 'del' && p !== 'los'; });
        return palabras.filter(function (p) { return new RegExp('\\b' + p + '\\b').test(frase); }).length;
    }

    /**
     * Interpreta una frase. ctx: {hoy: Date, medicos: [{id, nombre}], especialidades: [{id, nombre}]}.
     * Devuelve {comando} para «leer», «detener» y «ayuda»; o {tipo, desde, hasta, medico, especialidad, estado, ia, avisos, etiquetaFecha}.
     */
    function interpretar(frase, ctx) {
        var n = normalizar(frase);
        var nn = numeros(n);
        var hoy = ctx.hoy || new Date();
        var avisos = [];

        // Órdenes cortas
        if (/^(detener|detente|parar|para|basta|silencio|callate|cancelar)\b/.test(n)) { return { comando: 'detener' }; }
        if (/^(ayuda|ejemplos|que puedo (decir|pedir)|como funciona)\b/.test(n)) { return { comando: 'ayuda' }; }
        if (/^(leer|lee|lea|leeme|repetir|repite|repita|escuchar|escucha|dime|di)\b/.test(n) && !/\b(citas|ingresos|pacientes|agenda)\b/.test(n)) {
            return { comando: /\b(resumen|ia|inteligencia)\b/.test(n) ? 'resumen' : 'leer' };
        }

        var r = { tipo: null, desde: null, hasta: null, medico: null, especialidad: null, estado: null, ia: 'sin', avisos: avisos, etiquetaFecha: '' };

        // Modalidad
        if (/\bcon (ia|i a|inteligencia artificial|resumen)\b/.test(n)) { r.ia = 'con'; }

        // Tipo de reporte (de lo más específico a lo más general)
        var conteoEstados = ESTADOS.filter(function (e) { return e[1].test(n); }).map(function (e) { return e[0]; });
        if (/\b(ingres|cobr|recaud|pagos?\b|metodo de pago)/.test(n)) { r.tipo = 'ingresos'; }
        else if (/\bpacientes? (registrad|nuevos)|\bnuevos pacientes|\bregistrados\b/.test(n)) { r.tipo = 'pacientes'; }
        else if (/\bagenda\b/.test(n)) { r.tipo = 'agenda_medico'; }
        else if (/\b(atendid|cancelad|inasistenc|periodo)/.test(n) && conteoEstados.length !== 0 && !/\bpor estado\b/.test(n)) {
            r.tipo = 'periodo';
            if (conteoEstados.length === 1 && ['ATENDIDA', 'CANCELADA', 'INASISTENCIA'].indexOf(conteoEstados[0]) !== -1) { r.estado = conteoEstados[0]; }
        }
        else if (/\bpor medico|\bmedicos\b/.test(n)) { r.tipo = 'por_medico'; }
        else if (/\bpor especialidad|\bespecialidades\b|\bpor servicio/.test(n)) { r.tipo = 'por_especialidad'; }
        else if (/\bpor estado|\bestados\b/.test(n)) { r.tipo = 'por_estado'; }
        else if (/\bcitas?\b/.test(n)) { r.tipo = 'citas'; } // se decide abajo según haya un día o un rango

        // Fechas
        var f = fechas(nn, hoy);
        if (f && f.error) { avisos.push(f.error); f = null; }
        var unDia = r.tipo === 'agenda_medico' || r.tipo === 'citas';
        if (!f) {
            f = unDia ? { desde: iso(hoy), hasta: iso(hoy), texto: 'hoy' }
                : { desde: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), hasta: iso(new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0)), texto: 'este mes' };
            if (r.tipo) { avisos.push('No dijo fechas: uso ' + f.texto + '.'); }
        }
        r.desde = f.desde; r.hasta = f.hasta; r.etiquetaFecha = f.texto;
        if (r.tipo === 'citas') { r.tipo = f.desde === f.hasta ? 'citas_fecha' : 'por_estado'; }
        if (r.tipo === 'agenda_medico') { r.hasta = r.desde; }
        if (r.tipo === 'citas_fecha') { r.hasta = r.desde; }

        // Estado como filtro (en los reportes de citas)
        if (!r.estado && conteoEstados.length === 1 && ['por_medico', 'por_especialidad', 'por_estado', 'citas_fecha'].indexOf(r.tipo) !== -1) { r.estado = conteoEstados[0]; }

        // Médico y especialidad por su nombre
        var elegido = null; var empate = false; var mejor = 0;
        (ctx.medicos || []).forEach(function (m) {
            var puntos = coincidencias(n, m.nombre);
            if (puntos > mejor) { mejor = puntos; elegido = m; empate = false; } else if (puntos === mejor && puntos > 0) { empate = true; }
        });
        var pidioMedico = /\b(dr|dra|doctor|doctora|medico)\b/.test(n) || r.tipo === 'agenda_medico';
        if (elegido && !empate && (mejor >= 2 || pidioMedico)) { r.medico = elegido.id; }
        else if (elegido && empate) { avisos.push('Hay varios médicos con ese nombre: diga nombre y apellido.'); }
        (ctx.especialidades || []).forEach(function (e) {
            var nombre = normalizar(e.nombre.replace(/·.*$/, ''));
            if (nombre.length >= 4 && new RegExp('\\b' + nombre + '\\b').test(n)) { r.especialidad = e.id; }
        });
        if (r.tipo === 'agenda_medico' && !r.medico) { avisos.push('Falta el médico: diga, por ejemplo, «agenda de Nombre Apellido de mañana».'); }
        if (r.tipo === 'pacientes') { r.medico = null; r.especialidad = null; r.estado = null; }

        return r;
    }

    /** «Bs 1.234,50» -> «1234 bolivianos con 50 centavos» para que la voz lo lea bien. */
    function decirMontos(texto) {
        return texto.replace(/Bs\s*([\d.]+),(\d{2})/g, function (todo, entero, cent) {
            return entero.replace(/\./g, '') + (cent === '00' ? ' bolivianos' : ' bolivianos con ' + cent + ' centavos');
        });
    }

    var api = { interpretar: interpretar, normalizar: normalizar, decirMontos: decirMontos };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
    raiz.VozReportes = api;

    if (typeof document === 'undefined') { return; }

    // ---------------------------------------------------------------- Pantalla

    function iniciar() {
        var panel = document.getElementById('asistente-voz');
        var form = document.getElementById('form-reporte');
        if (!panel || !form) { return; }

        var campo = panel.querySelector('#voz-frase');
        var estado = panel.querySelector('#voz-estado');
        var botonEnviar = panel.querySelector('#voz-enviar');
        var botonMicro = panel.querySelector('#voz-microfono');
        var botonLeer = panel.querySelector('#voz-leer');
        var botonLeerIA = panel.querySelector('#voz-leer-ia');
        var botonDetener = panel.querySelector('#voz-detener');
        var Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;
        var puedeHablar = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
        var escuchando = null;

        var decir = function (texto) {
            estado.textContent = texto;
        };

        /** Lee el texto en voz alta (en frases cortas, porque algunos navegadores cortan las lecturas largas). */
        var hablar = function (texto) {
            if (!puedeHablar) { return; }
            window.speechSynthesis.cancel();
            var voces = window.speechSynthesis.getVoices();
            var voz = voces.filter(function (v) { return /^es/i.test(v.lang); })[0];
            decirMontos(texto).split(/(?<=[.!?:])\s+/).filter(Boolean).forEach(function (frase) {
                var lectura = new window.SpeechSynthesisUtterance(frase);
                lectura.lang = voz ? voz.lang : 'es-ES';
                if (voz) { lectura.voice = voz; }
                window.speechSynthesis.speak(lectura);
            });
        };

        var hayReporte = function () { return !!document.querySelector('#resultado-reporte .reporte'); };

        /** Título, período y SOLO los totales del reporte que está en pantalla (nunca el detalle ni nombres de pacientes). */
        var textoTotales = function () {
            var raizReporte = document.querySelector('#resultado-reporte .reporte');
            if (!raizReporte) { return ''; }
            var limpio = function (el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; };
            var partes = [limpio(raizReporte.querySelector('#titulo-reporte')) + '.', limpio(raizReporte.querySelector('.reporte__cabecera p')) + '.'];
            var vacio = raizReporte.querySelector('.vacio');
            if (vacio) { partes.push(limpio(vacio)); }
            raizReporte.querySelectorAll('.totales-reporte__dato').forEach(function (dato) {
                partes.push(limpio(dato.querySelector('dt')) + ': ' + limpio(dato.querySelector('dd')) + '.');
            });
            return partes.join(' ');
        };

        var actualizarBotones = function () {
            botonLeer.disabled = !hayReporte();
            botonLeerIA.hidden = !document.querySelector('#resultado-reporte .resumen-ia p');
        };

        var leerTotales = function () {
            if (!hayReporte()) { decir('Primero genere un reporte; por ejemplo: «citas de hoy».'); hablar('Primero genere un reporte.'); return; }
            var texto = textoTotales();
            decir('Leyendo los totales…');
            hablar(texto);
        };

        var leerResumenIA = function () {
            var parrafo = document.querySelector('#resultado-reporte .resumen-ia p');
            if (!parrafo) { decir('Este reporte no tiene resumen de IA: pida «con IA».'); return; }
            decir('Leyendo el resumen de IA…');
            hablar('Resumen generado por inteligencia artificial. ' + parrafo.textContent.replace(/\s+/g, ' ').trim());
        };

        var opciones = function (selector) {
            return Array.prototype.map.call(form.querySelectorAll(selector + ' option'), function (o) { return { id: o.value, nombre: o.textContent.trim() }; })
                .filter(function (o) { return o.id !== ''; });
        };

        /** Pone los filtros interpretados en el formulario (disparando el cambio de tipo para mostrar solo los filtros que sirven). */
        var llenarFormulario = function (r) {
            var poner = function (nombre, valor) { var el = form.querySelector('[name="' + nombre + '"]'); if (el) { el.value = valor || ''; } };
            poner('tipo', r.tipo);
            form.querySelector('#campo-tipo').dispatchEvent(new Event('change'));
            poner('desde', r.desde); poner('hasta', r.hasta);
            poner('medico', r.medico); poner('especialidad', r.especialidad); poner('estado', r.estado);
            var radio = form.querySelector('input[name="ia"][value="' + r.ia + '"]');
            if (radio) { radio.checked = true; }
        };

        var ejecutar = function (frase) {
            frase = (frase || '').trim();
            if (!frase) { decir('Escriba o dicte una frase. Pruebe con uno de los ejemplos.'); return; }
            campo.value = frase;
            var r = interpretar(frase, { hoy: new Date(), medicos: opciones('#campo-medico'), especialidades: opciones('#campo-especialidad') });

            if (r.comando === 'detener') { if (puedeHablar) { window.speechSynthesis.cancel(); } decir('Lectura detenida.'); return; }
            if (r.comando === 'leer') { leerTotales(); return; }
            if (r.comando === 'resumen') { leerResumenIA(); return; }
            if (r.comando === 'ayuda') {
                decir('Pruebe con una de las frases de ejemplo de abajo (haga clic en una). También puede decir «leer totales» o «detener».');
                hablar('Haga clic en una frase de ejemplo. También puede decir leer totales o detener.');
                return;
            }
            if (!r.tipo) {
                decir('No entendí qué reporte quiere. Pruebe con una de las frases de ejemplo.');
                hablar('No entendí qué reporte quiere. Pruebe con una de las frases de ejemplo.');
                return;
            }

            llenarFormulario(r);
            var nombreTipo = form.querySelector('#campo-tipo option[value="' + r.tipo + '"]').textContent;
            var entendi = 'Entendí: ' + nombreTipo + ' ' + r.etiquetaFecha + (r.ia === 'con' ? ', con IA' : ', sin IA') + (r.avisos.length ? ' · ' + r.avisos.join(' ') : '');
            decir(entendi + ' — generando el reporte…');

            var datos = new URLSearchParams(new FormData(form)).toString();
            var url = form.getAttribute('action') + '?' + datos;
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                .then(function (respuesta) { return respuesta.text(); })
                .then(function (html) {
                    var documento = new DOMParser().parseFromString(html, 'text/html');
                    var nuevo = documento.querySelector('#resultado-reporte');
                    if (!nuevo || !nuevo.querySelector('.reporte')) {
                        // Filtros inválidos u otro problema: se abre la página normal para ver los errores junto a cada campo.
                        window.location.href = url;
                        return;
                    }
                    document.getElementById('resultado-reporte').innerHTML = nuevo.innerHTML;
                    window.history.replaceState(null, '', url);
                    actualizarBotones();
                    decir(entendi + ' — listo.');
                    hablar((r.avisos.length ? r.avisos.join(' ') + ' ' : '') + textoTotales());
                })
                .catch(function () {
                    decir('No se pudo generar el reporte. Intente de nuevo.');
                });
        };

        // Botones y ejemplos
        botonEnviar.addEventListener('click', function () { ejecutar(campo.value); });
        campo.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); ejecutar(campo.value); } });
        panel.querySelectorAll('[data-frase]').forEach(function (b) {
            b.addEventListener('click', function () { ejecutar(b.getAttribute('data-frase')); });
        });
        botonLeer.addEventListener('click', leerTotales);
        botonLeerIA.addEventListener('click', leerResumenIA);
        botonDetener.addEventListener('click', function () { if (puedeHablar) { window.speechSynthesis.cancel(); } decir('Lectura detenida.'); });

        // Sin voz del navegador: solo queda el texto
        if (!puedeHablar) {
            botonLeer.hidden = true; botonLeerIA.hidden = true; botonDetener.hidden = true;
            panel.querySelector('#voz-sin-lectura').hidden = false;
        }
        if (!Reconocimiento) {
            botonMicro.hidden = true;
            panel.querySelector('#voz-sin-microfono').hidden = false;
        } else {
            botonMicro.addEventListener('click', function () {
                if (escuchando) { escuchando.stop(); return; }
                if (puedeHablar) { window.speechSynthesis.cancel(); }
                var rec = new Reconocimiento();
                rec.lang = 'es-BO';
                rec.interimResults = false;
                rec.maxAlternatives = 1;
                rec.onstart = function () { escuchando = rec; botonMicro.classList.add('escuchando'); botonMicro.setAttribute('aria-pressed', 'true'); decir('Escuchando… diga su frase.'); };
                rec.onresult = function (evento) { ejecutar(evento.results[0][0].transcript); };
                rec.onerror = function (evento) {
                    var mensajes = {
                        'not-allowed': 'El navegador no tiene permiso para usar el micrófono.',
                        'no-speech': 'No escuché nada. Intente de nuevo o escriba la frase.',
                        'network': 'El reconocimiento de voz del navegador necesita internet. Escriba la frase.',
                        'audio-capture': 'No se encontró un micrófono.'
                    };
                    decir(mensajes[evento.error] || 'No se pudo reconocer la voz. Escriba la frase.');
                };
                rec.onend = function () { escuchando = null; botonMicro.classList.remove('escuchando'); botonMicro.setAttribute('aria-pressed', 'false'); };
                rec.start();
            });
        }

        actualizarBotones();
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }
})(typeof window !== 'undefined' ? window : globalThis);
