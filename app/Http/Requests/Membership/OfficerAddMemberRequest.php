<?php

namespace App\Http\Requests\Membership;

use App\Enums\CpfRole;
use App\Models\CampusMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OfficerAddMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('addOrApproveMember', CampusMembership::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'role' => ['nullable', Rule::enum(CpfRole::class), Rule::notIn([CpfRole::Adviser->value, CpfRole::GuidanceCounselor->value])],
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'course' => ['required', 'string', 'max:60'],
            'year_level' => ['required', 'string', 'max:20'],
            'section' => ['required', 'string', 'max:20'],
            'course_year_section' => ['nullable', 'string', 'max:80'],
            'sex' => ['required', Rule::in(['Male', 'Female'])],
            'gender_identity' => ['nullable', Rule::in(['Man', 'Woman', 'Non-binary', 'Prefer not to say', 'Others'])],
            'gender_identity_other' => ['nullable', 'string', 'max:100'],
            'birthdate' => ['required', 'date', 'before:today'],
            'active_email' => ['required', 'email', 'max:255'],
            'facebook_profile_url' => [
                'required',
                'url',
                'max:500',
                'regex:/^https?:\/\/(www\.|m\.|web\.|l\.)?(facebook\.com|fb\.com)\/.+/i',
            ],
            'active_fb_account' => ['nullable', 'string', 'max:255'],
            'cellphone_number' => ['required', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'facebook_profile_url.required' => 'A valid Facebook profile link is required for roster verification.',
            'facebook_profile_url.url' => 'Please enter a valid Facebook profile URL (starting with https://).',
            'facebook_profile_url.regex' => 'Please enter a valid facebook.com or fb.com profile link.',
        ];
    }
}
