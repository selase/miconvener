import { useState, useEffect } from 'react';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Checkbox from '@/Components/Console/Checkbox';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import csrfFetch from '@/lib/csrfFetch';
import { 
    Plus, 
    Trash2, 
    Edit2, 
    ArrowUp, 
    ArrowDown, 
    Lock, 
    HelpCircle, 
    Sliders, 
    Sparkles, 
    Layers, 
    Eye,
    CheckCircle2
} from 'lucide-react';

const FIELD_TYPES = [
    { value: 'select', label: 'Dropdown Selection' },
    { value: 'radio', label: 'Radio Buttons' },
    { value: 'text', label: 'Single-line Text' },
    { value: 'textarea', label: 'Multi-line Text' },
    { value: 'checkbox', label: 'Checkbox' },
    { value: 'number', label: 'Number' },
];

export default function RegistrationFormPanel({ event, onChange }) {
    const [loading, setLoading] = useState(true);
    const [fields, setFields] = useState([]);
    const [settings, setSettings] = useState({
        collect_phone: true,
        require_phone: false,
        collect_dietary: true,
        require_dietary: false,
        collect_accessibility: true,
        require_accessibility: false,
    });
    const [savingSettings, setSavingSettings] = useState(false);
    const [settingsSuccess, setSettingsSuccess] = useState(false);

    // Modal state for adding/editing a custom field
    const [modalOpen, setModalOpen] = useState(false);
    const [editingField, setEditingField] = useState(null);
    const [fieldForm, setFieldForm] = useState(getInitialFieldState());
    const [fieldErrors, setFieldErrors] = useState({});
    const [savingField, setSavingField] = useState(false);

    // Delete confirmation
    const [deleteTarget, setDeleteTarget] = useState(null);

    // Live preview testing state
    const [previewAnswers, setPreviewAnswers] = useState({});

    function getInitialFieldState() {
        return {
            label: '',
            field_key: '',
            field_type: 'select',
            help_text: '',
            is_required: false,
            options: [
                { label: '', value: '', price: '' },
                { label: '', value: '', price: '' }
            ],
            has_condition: false,
            conditional_logic: {
                depends_on: '',
                operator: 'equals',
                value: ''
            }
        };
    }

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await csrfFetch(route('tenant.events.form-fields.index', { event: event.id }));
            if (res.ok) {
                const data = await res.json();
                setFields(data.form_fields || []);
                setSettings(data.registration_settings || {});
            }
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, [event.id]);

    const handleSaveSettings = async (e) => {
        e?.preventDefault();
        setSavingSettings(true);
        setSettingsSuccess(false);
        try {
            const res = await csrfFetch(route('tenant.events.registration-settings.update', { event: event.id }), {
                method: 'POST',
                body: JSON.stringify(settings),
            });
            if (res.ok) {
                setSettingsSuccess(true);
                setTimeout(() => setSettingsSuccess(false), 3000);
                if (onChange) onChange();
            }
        } finally {
            setSavingSettings(false);
        }
    };

    const openCreateModal = () => {
        setEditingField(null);
        setFieldForm(getInitialFieldState());
        setFieldErrors({});
        setModalOpen(true);
    };

    const openEditModal = (field) => {
        setEditingField(field);
        const hasCond = Boolean(field.conditional_logic && field.conditional_logic.depends_on);
        setFieldForm({
            label: field.label || '',
            field_key: field.field_key || '',
            field_type: field.field_type || 'select',
            help_text: field.help_text || '',
            is_required: Boolean(field.is_required),
            options: field.options && field.options.length > 0 
                ? field.options.map(o => ({
                    label: o.label || '',
                    value: o.value || '',
                    price: o.price ? (o.price / 100).toString() : '',
                    is_override: Boolean(o.is_override)
                })) 
                : [{ label: '', value: '', price: '' }],
            has_condition: hasCond,
            conditional_logic: hasCond ? {
                depends_on: field.conditional_logic.depends_on || '',
                operator: field.conditional_logic.operator || 'equals',
                value: field.conditional_logic.value || ''
            } : {
                depends_on: '',
                operator: 'equals',
                value: ''
            }
        });
        setFieldErrors({});
        setModalOpen(true);
    };

    const addOptionRow = () => {
        setFieldForm(prev => ({
            ...prev,
            options: [...prev.options, { label: '', value: '', price: '' }]
        }));
    };

    const removeOptionRow = (index) => {
        setFieldForm(prev => ({
            ...prev,
            options: prev.options.filter((_, i) => i !== index)
        }));
    };

    const updateOptionRow = (index, key, val) => {
        setFieldForm(prev => {
            const nextOpts = [...prev.options];
            nextOpts[index] = { ...nextOpts[index], [key]: val };
            if (key === 'label' && (!nextOpts[index].value || nextOpts[index].value === slugify(nextOpts[index].label.slice(0, -1)))) {
                nextOpts[index].value = slugify(val);
            }
            return { ...prev, options: nextOpts };
        });
    };

    const slugify = (text) => {
        return text.toString().toLowerCase().trim()
            .replace(/\s+/g, '_')
            .replace(/[^\w-]+/g, '')
            .replace(/--+/g, '_');
    };

    const handleSaveField = async (e) => {
        e.preventDefault();
        setSavingField(true);
        setFieldErrors({});

        const payload = {
            label: fieldForm.label,
            field_key: fieldForm.field_key || slugify(fieldForm.label),
            field_type: fieldForm.field_type,
            help_text: fieldForm.help_text,
            is_required: fieldForm.is_required,
            options: ['select', 'radio', 'checkbox'].includes(fieldForm.field_type)
                ? fieldForm.options
                    .filter(o => o.label.trim() !== '')
                    .map(o => ({
                        label: o.label.trim(),
                        value: o.value.trim() || slugify(o.label),
                        price: o.price !== '' && !isNaN(Number(o.price)) ? Math.round(Number(o.price) * 100) : null,
                        is_override: Boolean(o.is_override)
                    }))
                : null,
            conditional_logic: fieldForm.has_condition && fieldForm.conditional_logic.depends_on
                ? fieldForm.conditional_logic
                : null
        };

        const url = editingField 
            ? route('tenant.events.form-fields.update', { event: event.id, field: editingField.id })
            : route('tenant.events.form-fields.store', { event: event.id });
        const method = editingField ? 'PUT' : 'POST';

        try {
            const res = await csrfFetch(url, {
                method,
                body: JSON.stringify(payload)
            });

            if (res.ok) {
                setModalOpen(false);
                loadData();
                if (onChange) onChange();
            } else if (res.status === 422) {
                const errData = await res.json();
                setFieldErrors(errData.errors || {});
            }
        } finally {
            setSavingField(false);
        }
    };

    const handleDeleteField = async () => {
        if (!deleteTarget) return;
        try {
            const res = await csrfFetch(route('tenant.events.form-fields.destroy', { event: event.id, field: deleteTarget.id }), {
                method: 'DELETE'
            });
            if (res.ok) {
                setDeleteTarget(null);
                loadData();
                if (onChange) onChange();
            }
        } catch (e) {
            console.error(e);
        }
    };

    const handleMoveField = async (index, direction) => {
        const targetIndex = index + direction;
        if (targetIndex < 0 || targetIndex >= fields.length) return;

        const newOrder = [...fields];
        const temp = newOrder[index];
        newOrder[index] = newOrder[targetIndex];
        newOrder[targetIndex] = temp;

        setFields(newOrder);

        await csrfFetch(route('tenant.events.form-fields.reorder', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({ order: newOrder.map(f => f.id) })
        });
    };

    // Candidate parent fields for conditional logic (must be select or radio)
    const eligibleParentFields = fields.filter(f => 
        ['select', 'radio'].includes(f.field_type) && 
        (!editingField || f.id !== editingField.id)
    );

    const selectedParent = eligibleParentFields.find(f => f.field_key === fieldForm.conditional_logic.depends_on);

    return (
        <div className="space-y-8 max-w-5xl">
            {/* Standard Core Identity & Configurable Fields */}
            <div className="border border-border bg-surface rounded-lg p-6">
                <div className="flex items-center justify-between border-b border-border pb-4">
                    <div>
                        <h3 className="text-base font-semibold text-ink flex items-center gap-2">
                            <Lock className="h-4 w-4 text-accent" />
                            Core Registration Identity Fields
                        </h3>
                        <p className="text-xs text-ink-secondary mt-1">
                            MiConvener guarantees standard attendee verification for all registrations.
                        </p>
                    </div>
                </div>

                <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
                    <div className="p-3 bg-canvas border border-border rounded flex items-center justify-between">
                        <span className="text-sm font-medium text-ink">Title (Dr., Prof., etc.)</span>
                        <span className="text-[11px] bg-accent/10 text-accent font-semibold px-2 py-0.5 rounded">Required</span>
                    </div>
                    <div className="p-3 bg-canvas border border-border rounded flex items-center justify-between">
                        <span className="text-sm font-medium text-ink">First Name</span>
                        <span className="text-[11px] bg-accent/10 text-accent font-semibold px-2 py-0.5 rounded">Required</span>
                    </div>
                    <div className="p-3 bg-canvas border border-border rounded flex items-center justify-between">
                        <span className="text-sm font-medium text-ink">Last Name</span>
                        <span className="text-[11px] bg-accent/10 text-accent font-semibold px-2 py-0.5 rounded">Required</span>
                    </div>
                    <div className="p-3 bg-canvas border border-border rounded flex items-center justify-between">
                        <span className="text-sm font-medium text-ink">Email Address</span>
                        <span className="text-[11px] bg-accent/10 text-accent font-semibold px-2 py-0.5 rounded">Required</span>
                    </div>
                </div>

                <div className="mt-6 pt-5 border-t border-border">
                    <h4 className="text-xs font-semibold text-ink-secondary uppercase tracking-wider mb-3">
                        Configurable Standard Requirements
                    </h4>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        {/* Phone Number Requirement */}
                        <div className="p-3 border border-border rounded bg-surface">
                            <label className="text-xs font-medium text-ink block mb-2">Phone Number</label>
                            <select 
                                value={!settings.collect_phone ? 'disabled' : (settings.require_phone ? 'required' : 'optional')}
                                onChange={(e) => {
                                    const val = e.target.value;
                                    setSettings(prev => ({
                                        ...prev,
                                        collect_phone: val !== 'disabled',
                                        require_phone: val === 'required'
                                    }));
                                }}
                                className="w-full text-xs rounded border border-border bg-surface px-2.5 py-1.5 text-ink focus:border-accent"
                            >
                                <option value="optional">Optional</option>
                                <option value="required">Mandatory / Required</option>
                                <option value="disabled">Hidden / Disabled</option>
                            </select>
                        </div>

                        {/* Dietary Requirements */}
                        <div className="p-3 border border-border rounded bg-surface">
                            <label className="text-xs font-medium text-ink block mb-2">Dietary Requirements</label>
                            <select 
                                value={!settings.collect_dietary ? 'disabled' : (settings.require_dietary ? 'required' : 'optional')}
                                onChange={(e) => {
                                    const val = e.target.value;
                                    setSettings(prev => ({
                                        ...prev,
                                        collect_dietary: val !== 'disabled',
                                        require_dietary: val === 'required'
                                    }));
                                }}
                                className="w-full text-xs rounded border border-border bg-surface px-2.5 py-1.5 text-ink focus:border-accent"
                            >
                                <option value="optional">Optional</option>
                                <option value="required">Mandatory / Required</option>
                                <option value="disabled">Hidden / Disabled</option>
                            </select>
                        </div>

                        {/* Accessibility Needs */}
                        <div className="p-3 border border-border rounded bg-surface">
                            <label className="text-xs font-medium text-ink block mb-2">Accessibility Needs</label>
                            <select 
                                value={!settings.collect_accessibility ? 'disabled' : (settings.require_accessibility ? 'required' : 'optional')}
                                onChange={(e) => {
                                    const val = e.target.value;
                                    setSettings(prev => ({
                                        ...prev,
                                        collect_accessibility: val !== 'disabled',
                                        require_accessibility: val === 'required'
                                    }));
                                }}
                                className="w-full text-xs rounded border border-border bg-surface px-2.5 py-1.5 text-ink focus:border-accent"
                            >
                                <option value="optional">Optional</option>
                                <option value="required">Mandatory / Required</option>
                                <option value="disabled">Hidden / Disabled</option>
                            </select>
                        </div>
                    </div>

                    <div className="mt-4 flex items-center justify-between">
                        <span className="text-xs text-ink-secondary">
                            {settingsSuccess && (
                                <span className="text-success-fg font-medium flex items-center gap-1">
                                    <CheckCircle2 className="h-3.5 w-3.5" /> Settings saved successfully!
                                </span>
                            )}
                        </span>
                        <Button 
                            variant="secondary" 
                            size="sm" 
                            onClick={handleSaveSettings}
                            disabled={savingSettings}
                        >
                            {savingSettings ? 'Saving...' : 'Save Requirements'}
                        </Button>
                    </div>
                </div>
            </div>

            {/* Custom Form Fields & Conditional Pricing */}
            <div className="border border-border bg-surface rounded-lg p-6">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-border pb-4">
                    <div>
                        <h3 className="text-base font-semibold text-ink flex items-center gap-2">
                            <Sliders className="h-4 w-4 text-accent" />
                            Custom Registration Fields & Conditional Pricing
                        </h3>
                        <p className="text-xs text-ink-secondary mt-1">
                            Add custom questions, attendance categories, professional cadres, and attach pricing or conditional rules.
                        </p>
                    </div>
                    <Button icon={Plus} onClick={openCreateModal} size="sm">
                        Add Custom Field
                    </Button>
                </div>

                {loading ? (
                    <div className="py-8 text-center text-xs text-ink-secondary">Loading form fields...</div>
                ) : fields.length === 0 ? (
                    <div className="py-12 text-center">
                        <Layers className="h-8 w-8 text-ink-secondary/40 mx-auto mb-2" />
                        <p className="text-sm font-medium text-ink">No custom fields created yet</p>
                        <p className="text-xs text-ink-secondary max-w-sm mx-auto mt-1 mb-4">
                            You can define custom questions, attendance types (In-Person / Virtual), roles (Doctor, Nurse, Student), and attach conditional pricing.
                        </p>
                        <Button icon={Plus} onClick={openCreateModal} size="sm" variant="secondary">
                            Add First Custom Field
                        </Button>
                    </div>
                ) : (
                    <div className="divide-y divide-border mt-2">
                        {fields.map((field, idx) => (
                            <div key={field.id} className="py-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="text-sm font-medium text-ink">{field.label}</span>
                                        <code className="text-[11px] bg-canvas px-1.5 py-0.5 rounded text-ink-secondary border border-border">
                                            {field.field_key}
                                        </code>
                                        <span className="text-[11px] border border-border px-2 py-0.2 rounded text-ink-secondary capitalize">
                                            {field.field_type}
                                        </span>
                                        {field.is_required ? (
                                            <span className="text-[11px] bg-accent/10 text-accent font-semibold px-2 py-0.2 rounded">
                                                Required
                                            </span>
                                        ) : (
                                            <span className="text-[11px] bg-canvas text-ink-secondary px-2 py-0.2 rounded">
                                                Optional
                                            </span>
                                        )}
                                    </div>

                                    {/* Options & Pricing Preview */}
                                    {field.options && field.options.length > 0 && (
                                        <div className="flex items-center gap-2 flex-wrap pt-1">
                                            {field.options.map((opt, oIdx) => (
                                                <span key={oIdx} className="text-xs bg-canvas px-2 py-0.5 rounded border border-border text-ink flex items-center gap-1.5">
                                                    <span>{opt.label}</span>
                                                    {opt.price ? (
                                                        <span className="font-mono text-accent font-semibold text-[11px]">
                                                            {event.currency} {(opt.price / 100).toFixed(2)}
                                                        </span>
                                                    ) : null}
                                                </span>
                                            ))}
                                        </div>
                                    )}

                                    {/* Conditional Logic Display */}
                                    {field.conditional_logic && field.conditional_logic.depends_on && (
                                        <div className="pt-1 flex items-center gap-1 text-[11px] text-accent">
                                            <Sparkles className="h-3 w-3" />
                                            <span>
                                                Shown conditionally when <strong>{field.conditional_logic.depends_on}</strong> = "{field.conditional_logic.value}"
                                            </span>
                                        </div>
                                    )}
                                </div>

                                <div className="flex items-center gap-1 shrink-0 self-end md:self-center">
                                    <button 
                                        type="button" 
                                        onClick={() => handleMoveField(idx, -1)}
                                        disabled={idx === 0}
                                        className="p-1.5 text-ink-secondary hover:text-ink disabled:opacity-30"
                                        title="Move Up"
                                    >
                                        <ArrowUp className="h-3.5 w-3.5" />
                                    </button>
                                    <button 
                                        type="button" 
                                        onClick={() => handleMoveField(idx, 1)}
                                        disabled={idx === fields.length - 1}
                                        className="p-1.5 text-ink-secondary hover:text-ink disabled:opacity-30"
                                        title="Move Down"
                                    >
                                        <ArrowDown className="h-3.5 w-3.5" />
                                    </button>
                                    <button 
                                        type="button" 
                                        onClick={() => openEditModal(field)}
                                        className="p-1.5 text-ink-secondary hover:text-accent ml-1"
                                        title="Edit Field"
                                    >
                                        <Edit2 className="h-3.5 w-3.5" />
                                    </button>
                                    <button 
                                        type="button" 
                                        onClick={() => setDeleteTarget(field)}
                                        className="p-1.5 text-ink-secondary hover:text-danger-fg"
                                        title="Delete Field"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Live Interactive Form & Pricing Preview */}
            {fields.length > 0 && (
                <div className="border border-border bg-surface rounded-lg p-6">
                    <div className="flex items-center justify-between border-b border-border pb-4">
                        <div>
                            <h3 className="text-base font-semibold text-ink flex items-center gap-2">
                                <Eye className="h-4 w-4 text-accent" />
                                Interactive Form & Pricing Preview
                            </h3>
                            <p className="text-xs text-ink-secondary mt-1">
                                Try out your form's conditional questions and verify that prices calculate as expected.
                            </p>
                        </div>
                    </div>

                    <div className="mt-4 p-5 bg-canvas rounded-lg border border-border max-w-lg">
                        <div className="space-y-4">
                            {/* Standard Required Identity */}
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <label className="text-[11px] text-ink-secondary">Title *</label>
                                    <input disabled value="Dr." className="w-full bg-surface/50 border border-border rounded px-2.5 py-1.5 text-xs text-ink" />
                                </div>
                                <div>
                                    <label className="text-[11px] text-ink-secondary">Full Name *</label>
                                    <input disabled value="Kwame Mensah" className="w-full bg-surface/50 border border-border rounded px-2.5 py-1.5 text-xs text-ink" />
                                </div>
                            </div>

                            {/* Dynamic Fields */}
                            {fields.map(field => {
                                // Evaluate conditional rule
                                if (field.conditional_logic && field.conditional_logic.depends_on) {
                                    const parentVal = previewAnswers[field.conditional_logic.depends_on];
                                    if (parentVal !== field.conditional_logic.value) {
                                        return null; // Hidden
                                    }
                                }

                                return (
                                    <div key={field.id} className="p-2.5 rounded bg-surface border border-border">
                                        <label className="text-xs font-medium text-ink block mb-1">
                                            {field.label} {field.is_required && <span className="text-accent">*</span>}
                                        </label>
                                        {field.help_text && (
                                            <p className="text-[11px] text-ink-secondary mb-2">{field.help_text}</p>
                                        )}

                                        {['select', 'radio'].includes(field.field_type) ? (
                                            <div className="space-y-1.5">
                                                {field.options?.map((opt, idx) => (
                                                    <label key={idx} className="flex items-center justify-between gap-2 text-xs text-ink cursor-pointer p-1.5 rounded hover:bg-canvas">
                                                        <span className="flex items-center gap-2">
                                                            <input 
                                                                type="radio" 
                                                                name={field.field_key}
                                                                value={opt.value}
                                                                checked={previewAnswers[field.field_key] === opt.value}
                                                                onChange={(e) => setPreviewAnswers(prev => ({ ...prev, [field.field_key]: e.target.value }))}
                                                            />
                                                            <span>{opt.label}</span>
                                                        </span>
                                                        {opt.price ? (
                                                            <span className="font-mono text-accent font-semibold text-[11px]">
                                                                {event.currency} {(opt.price / 100).toFixed(2)}
                                                            </span>
                                                        ) : null}
                                                    </label>
                                                ))}
                                            </div>
                                        ) : (
                                            <input 
                                                type={field.field_type === 'number' ? 'number' : 'text'}
                                                placeholder={`Enter ${field.label.toLowerCase()}`}
                                                className="w-full text-xs rounded border border-border bg-surface px-2.5 py-1.5 text-ink"
                                                value={previewAnswers[field.field_key] || ''}
                                                onChange={(e) => setPreviewAnswers(prev => ({ ...prev, [field.field_key]: e.target.value }))}
                                            />
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            )}

            {/* Field Create/Edit Modal */}
            {modalOpen && (
                <Modal
                    title={editingField ? 'Edit Registration Field' : 'Add Registration Field'}
                    onClose={() => setModalOpen(false)}
                >
                    <form onSubmit={handleSaveField} className="space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <Input
                                label="Field Label *"
                                placeholder="e.g. Attendance Mode, Professional Cadre"
                                value={fieldForm.label}
                                onChange={(e) => setFieldForm(prev => ({
                                    ...prev, 
                                    label: e.target.value,
                                    field_key: !editingField && (!prev.field_key || prev.field_key === slugify(prev.label))
                                        ? slugify(e.target.value)
                                        : prev.field_key
                                }))}
                                error={fieldErrors.label?.[0]}
                            />

                            <Input
                                label="Identifier / Key *"
                                placeholder="e.g. attendance_mode, cadre"
                                value={fieldForm.field_key}
                                onChange={(e) => setFieldForm(prev => ({ ...prev, field_key: slugify(e.target.value) }))}
                                error={fieldErrors.field_key?.[0]}
                            />
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <Select
                                label="Field Input Type *"
                                value={fieldForm.field_type}
                                onChange={(e) => setFieldForm(prev => ({ ...prev, field_type: e.target.value }))}
                                options={FIELD_TYPES}
                            />

                            <div className="pt-6">
                                <label className="flex items-center gap-2 cursor-pointer text-sm text-ink">
                                    <input
                                        type="checkbox"
                                        checked={fieldForm.is_required}
                                        onChange={(e) => setFieldForm(prev => ({ ...prev, is_required: e.target.checked }))}
                                        className="rounded border-border text-accent focus:ring-accent"
                                    />
                                    <span>Required Field (Attendee must answer)</span>
                                </label>
                            </div>
                        </div>

                        <Input
                            label="Help Text / Hint (Optional)"
                            placeholder="Helpful instruction displayed under the question"
                            value={fieldForm.help_text}
                            onChange={(e) => setFieldForm(prev => ({ ...prev, help_text: e.target.value }))}
                        />

                        {/* Options & Pricing (for select, radio, checkbox) */}
                        {['select', 'radio', 'checkbox'].includes(fieldForm.field_type) && (
                            <div className="border border-border rounded-lg p-4 bg-canvas space-y-3">
                                <div className="flex items-center justify-between">
                                    <label className="text-xs font-semibold text-ink uppercase tracking-wider">
                                        Choices & Pricing Rules
                                    </label>
                                    <Button size="xs" variant="secondary" onClick={addOptionRow} type="button">
                                        Add Choice
                                    </Button>
                                </div>
                                <p className="text-[11px] text-ink-secondary">
                                    Add your choices. If an option affects pricing (e.g. Doctor = 100, Nurse = 80), specify the amount in {event.currency}.
                                </p>

                                <div className="space-y-2">
                                    {fieldForm.options.map((opt, oIdx) => (
                                        <div key={oIdx} className="flex items-center gap-2">
                                            <input
                                                type="text"
                                                placeholder="Choice Label (e.g. Doctor)"
                                                value={opt.label}
                                                onChange={(e) => updateOptionRow(oIdx, 'label', e.target.value)}
                                                className="flex-1 text-xs rounded border border-border bg-surface px-2.5 py-1.5 text-ink"
                                            />
                                            <input
                                                type="text"
                                                placeholder="Value (e.g. doctor)"
                                                value={opt.value}
                                                onChange={(e) => updateOptionRow(oIdx, 'value', e.target.value)}
                                                className="w-28 text-xs rounded border border-border bg-surface px-2.5 py-1.5 text-ink"
                                            />
                                            <div className="flex items-center gap-1 w-32">
                                                <span className="text-[11px] text-ink-secondary">{event.currency}</span>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    placeholder="Price (opt)"
                                                    value={opt.price}
                                                    onChange={(e) => updateOptionRow(oIdx, 'price', e.target.value)}
                                                    className="w-full text-xs rounded border border-border bg-surface px-2 py-1.5 text-ink font-mono"
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => removeOptionRow(oIdx)}
                                                disabled={fieldForm.options.length <= 1}
                                                className="p-1 text-ink-secondary hover:text-danger-fg disabled:opacity-30"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Conditional Visibility Logic */}
                        <div className="border border-border rounded-lg p-4 bg-canvas space-y-3">
                            <label className="flex items-center gap-2 cursor-pointer text-xs font-semibold text-ink uppercase tracking-wider">
                                <input
                                    type="checkbox"
                                    checked={fieldForm.has_condition}
                                    onChange={(e) => setFieldForm(prev => ({ ...prev, has_condition: e.target.checked }))}
                                    className="rounded border-border text-accent focus:ring-accent"
                                />
                                <span>Conditional Visibility</span>
                            </label>

                            {fieldForm.has_condition && (
                                <div className="space-y-3 pt-2">
                                    <p className="text-[11px] text-ink-secondary">
                                        Only show this field if the attendee selected a specific answer on another question.
                                    </p>

                                    {eligibleParentFields.length === 0 ? (
                                        <p className="text-xs text-warning-fg bg-warning-bg p-2 rounded">
                                            Create a parent Dropdown or Radio question (such as Attendance Mode) first to use conditional logic.
                                        </p>
                                    ) : (
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div>
                                                <label className="text-[11px] text-ink-secondary block mb-1">Parent Question</label>
                                                <select
                                                    value={fieldForm.conditional_logic.depends_on}
                                                    onChange={(e) => setFieldForm(prev => ({
                                                        ...prev,
                                                        conditional_logic: { ...prev.conditional_logic, depends_on: e.target.value, value: '' }
                                                    }))}
                                                    className="w-full text-xs rounded border border-border bg-surface px-2.5 py-1.5 text-ink"
                                                >
                                                    <option value="">-- Select Parent Question --</option>
                                                    {eligibleParentFields.map(f => (
                                                        <option key={f.id} value={f.field_key}>{f.label} ({f.field_key})</option>
                                                    ))}
                                                </select>
                                            </div>

                                            <div>
                                                <label className="text-[11px] text-ink-secondary block mb-1">When answer equals</label>
                                                {selectedParent?.options ? (
                                                    <select
                                                        value={fieldForm.conditional_logic.value}
                                                        onChange={(e) => setFieldForm(prev => ({
                                                            ...prev,
                                                            conditional_logic: { ...prev.conditional_logic, value: e.target.value }
                                                        }))}
                                                        className="w-full text-xs rounded border border-border bg-surface px-2.5 py-1.5 text-ink"
                                                    >
                                                        <option value="">-- Select Choice --</option>
                                                        {selectedParent.options.map((opt, oIdx) => (
                                                            <option key={oIdx} value={opt.value}>{opt.label}</option>
                                                        ))}
                                                    </select>
                                                ) : (
                                                    <input
                                                        type="text"
                                                        placeholder="e.g. in_person"
                                                        value={fieldForm.conditional_logic.value}
                                                        onChange={(e) => setFieldForm(prev => ({
                                                            ...prev,
                                                            conditional_logic: { ...prev.conditional_logic, value: e.target.value }
                                                        }))}
                                                        className="w-full text-xs rounded border border-border bg-surface px-2.5 py-1.5 text-ink"
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="flex justify-end gap-2 pt-3 border-t border-border">
                            <Button variant="secondary" type="button" onClick={() => setModalOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={savingField}>
                                {savingField ? 'Saving...' : (editingField ? 'Update Field' : 'Create Field')}
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}

            {/* Confirm Delete Modal */}
            {deleteTarget && (
                <ConfirmModal
                    title="Delete Form Field?"
                    message={`Are you sure you want to delete "${deleteTarget.label}"? Any existing attendee answers for this field will remain in historical records.`}
                    confirmText="Delete Field"
                    destructive
                    onConfirm={handleDeleteField}
                    onClose={() => setDeleteTarget(null)}
                />
            )}
        </div>
    );
}
