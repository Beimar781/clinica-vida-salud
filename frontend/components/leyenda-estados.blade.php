{{-- Leyenda corta de estados de la cita (CU-10, CU-13, CU-15): una línea de chips con los mismos colores de <x-estado-cita>, dentro de un <details> cerrado. --}}
<details {{ $attributes->class(['leyenda-estados']) }}>
    <summary>¿Qué significa cada estado?</summary>
    <ul class="leyenda-estados__lista">
        <li><span class="etiqueta-estado estado-programada">Programada</span> reservada, falta confirmar</li>
        <li><span class="etiqueta-estado estado-confirmada">Confirmada</span> asistencia confirmada</li>
        <li><span class="etiqueta-estado estado-atendida">Atendida</span> el médico la atendió</li>
        <li><span class="etiqueta-estado estado-cancelada">Cancelada</span> anulada, el horario queda libre</li>
        <li><span class="etiqueta-estado estado-inasistencia">Inasistencia</span> el paciente no asistió</li>
        <li><span class="etiqueta-estado etiqueta-vencida">Vencida</span> ya pasó la hora y nadie la cerró (no es un estado)</li>
        <li><span class="etiqueta-estado pago-pendiente">Pendiente</span><span class="etiqueta-estado pago-pagado">Pagado</span> pago de la cita</li>
    </ul>
</details>
