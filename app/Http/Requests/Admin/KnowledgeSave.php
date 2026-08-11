<?php

namespace App\Http\Requests\Admin;

use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\Knowledge;
use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KnowledgeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'category' => 'required',
            'language' => 'required',
            'title' => 'required',
            'body' => 'required',
            'show' => 'nullable|boolean',
            'scope_type' => ['nullable', Rule::in(Knowledge::SCOPE_TYPES)],
            'site_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn (): bool => $this->input('scope_type', Knowledge::SCOPE_GLOBAL) === Knowledge::SCOPE_SITE),
                Rule::exists((new Site())->getTable(), 'id'),
            ],
            'agent_user_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn (): bool => $this->input('scope_type', Knowledge::SCOPE_GLOBAL) === Knowledge::SCOPE_AGENT),
                Rule::exists((new AgentProfile())->getTable(), 'user_id'),
            ],
            'agent_domain_id' => [
                'nullable',
                'integer',
                Rule::exists((new AgentDomain())->getTable(), 'id')
                    ->where(fn ($query) => $query->where('agent_user_id', $this->input('agent_user_id'))),
            ],
        ];
    }

    public function messages()
    {
        return [
            'title.required' => '标题不能为空',
            'category.required' => '分类不能为空',
            'body.required' => '内容不能为空',
            'language.required' => '语言不能为空',
            'show.boolean' => '显示状态必须为布尔值',
            'scope_type.in' => '知识适用范围不正确',
            'site_id.required' => '请选择适用分站',
            'site_id.exists' => '所选分站不存在',
            'agent_user_id.required' => '请选择适用代理',
            'agent_user_id.exists' => '所选代理不存在',
            'agent_domain_id.exists' => '所选代理域名不存在或不属于该代理',
        ];
    }


    protected function prepareForValidation(): void
    {
        $scopeProvided = $this->has('scope_type');
        $scope = (string) $this->input('scope_type', Knowledge::SCOPE_GLOBAL);
        $siteId = $this->input('site_id');
        $agentUserId = $this->input('agent_user_id');
        $agentDomainId = $this->input('agent_domain_id');

        if (!$scopeProvided && $this->input('id')) {
            $existing = Knowledge::find($this->input('id'));
            if ($existing) {
                $scope = (string) ($existing->getAttribute('scope_type') ?: Knowledge::SCOPE_GLOBAL);
                $siteId = $existing->getAttribute('site_id');
                $agentUserId = $existing->getAttribute('agent_user_id');
                $agentDomainId = $existing->getAttribute('agent_domain_id');
            }
        }

        $this->merge([
            'scope_type' => $scope,
            'site_id' => $scope === Knowledge::SCOPE_SITE ? $siteId : null,
            'agent_user_id' => $scope === Knowledge::SCOPE_AGENT ? $agentUserId : null,
            'agent_domain_id' => $scope === Knowledge::SCOPE_AGENT ? $agentDomainId : null,
        ]);
    }
}
