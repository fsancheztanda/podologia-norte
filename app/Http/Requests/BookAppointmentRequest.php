<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ruta pública
    }

    public function rules(): array
    {
        return [
            'dni'             => 'required|string|min:7|max:20',
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'phone'           => 'required|string|min:8|max:20',
            'email'           => 'nullable|email|max:150',
            'professional_id' => 'required|uuid|exists:users,id',
            'datetime'        => 'required|date|after:now',
        ];
    }

    public function messages(): array
    {
        return [
            'dni.required'             => 'El DNI es obligatorio.',
            'first_name.required'      => 'El nombre es obligatorio.',
            'last_name.required'       => 'El apellido es obligatorio.',
            'phone.required'           => 'El teléfono es obligatorio.',
            'professional_id.required' => 'Elegí una profesional.',
            'professional_id.exists'   => 'La profesional seleccionada no existe.',
            'datetime.required'        => 'Seleccioná un horario.',
            'datetime.after'           => 'El horario debe ser en el futuro.',
        ];
    }
}
