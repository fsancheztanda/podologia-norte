import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { 
  Calendar, User, Clock, CheckCircle, ChevronRight, 
  LogIn, LogOut, ClipboardList, Filter, DollarSign, 
  Users, Search, PlusCircle, History, ArrowLeft, Phone, Mail, TrendingUp, Download, ShieldAlert, X
} from 'lucide-react';

// --- TIPOS ---
interface Professional {
  id: string;
  name: string;
  appointment_duration_min: number;
  role: string;
}

interface Slot {
  time: string;
  datetime: string;
  available: boolean;
}

interface Patient {
  id: string;
  dni: string;
  full_name: string;
  phone?: string;
  email?: string;
  dob?: string;
  visit_count: number;
  last_visit_at: string;
}

interface MedicalRecord {
  id: string;
  visit_date: string;
  treatment: string;
  notes: string;
  professional: { name: string };
}

interface Appointment {
  id: string;
  starts_at: string;
  status: string;
  notes?: string;
  professional: { id: string; name: string };
  patient: { id: string; full_name: string; phone?: string; email?: string };
  fee: string;
}

interface FinancialReport {
  totals: {
    total_appointments: number;
    total_income: number;
    total_owner: number;
    total_employee: number;
  };
  by_professional: Array<{
    user_id: string;
    professional: { name: string; role: string };
    appointments: number;
    income: number;
    owner_share: number;
    employee_share: number;
  }>;
}

const API_BASE = import.meta.env.VITE_API_URL 
  ? `${import.meta.env.VITE_API_URL}/api`
  : 'http://localhost:8000/api';
  
function App() {
  const [view, setView] = useState<'public' | 'login' | 'admin'>('public');
  const [adminSubView, setAdminSubView] = useState<'agenda' | 'patients' | 'finance'>('agenda');
  const [token, setToken] = useState<string | null>(localStorage.getItem('token'));
  const [user, setUser] = useState<any>(JSON.parse(localStorage.getItem('user') || 'null'));

  // --- ESTADO PÚBLICO ---
  const [step, setStep] = useState(1);
  const [professionals, setProfessionals] = useState<Professional[]>([]);
  const [selectedProf, setSelectedProf] = useState<Professional | null>(null);
  const [date, setDate] = useState(new Date().toISOString().split('T')[0]);
  const [slots, setSlots] = useState<Slot[]>([]);
  const [selectedSlot, setSelectedSlot] = useState<Slot | null>(null);
  const [loading, setLoading] = useState(false);
  const [formData, setFormData] = useState({ dni: '', first_name: '', last_name: '', phone: '', email: '' });
  const [confirmed, setConfirmed] = useState<any>(null);

  // --- ESTADO ADMIN ---
  const [adminAppointments, setAdminAppointments] = useState<Appointment[]>([]);
  const [adminDate, setAdminDate] = useState(new Date().toISOString().split('T')[0]);
  const [calendarMode, setCalendarMode] = useState<'daily' | 'weekly'>('daily');
  const [loginForm, setLoginForm] = useState({ email: '', password: '' });
  const [showBlockModal, setShowBlockModal] = useState(false);
  const [blockForm, setBlockData] = useState({ professional_id: '', time: '09:00', notes: '' });

  // --- ESTADO PACIENTES ---
  const [patients, setPatients] = useState<Patient[]>([]);
  const [selectedPatient, setSelectedPatient] = useState<any>(null);
  const [patientSearch, setPatientSearch] = useState('');
  const [newRecord, setNewRecord] = useState({ visit_date: new Date().toISOString().split('T')[0], treatment: '', notes: '' });

  // --- ESTADO FINANZAS ---
  const [financialReport, setFinancialReport] = useState<FinancialReport | null>(null);
  const [financeRange, setFinanceRange] = useState({
    from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0]
  });

  // Cargar profesionales
  useEffect(() => {
    axios.get(`${API_BASE}/professionals`).then(res => setProfessionals(res.data.data));
  }, []);

  // Cargar disponibilidad
  useEffect(() => {
    if (selectedProf && date) {
      setLoading(true);
      axios.get(`${API_BASE}/availability`, { params: { professional_id: selectedProf.id, date } })
        .then(res => setSlots(res.data.slots))
        .finally(() => setLoading(false));
    }
  }, [selectedProf, date]);

  // Cargar Agenda Admin
  const fetchAgenda = () => {
    if (!token) return;
    setLoading(true);

    let params: any = { date: adminDate };
    
    if (calendarMode === 'weekly') {
      const current = new Date(adminDate);
      const day = current.getDay(); // 0 (Sun) to 6 (Sat)
      const diff = current.getDate() - day + (day === 0 ? -6 : 1); // Adjust to Monday
      const monday = new Date(current.setDate(diff));
      const sunday = new Date(current.setDate(diff + 6));
      
      params = {
        start_date: monday.toISOString().split('T')[0],
        end_date: sunday.toISOString().split('T')[0]
      };
    }

    axios.get(`${API_BASE}/admin/appointments`, {
      params,
      headers: { Authorization: `Bearer ${token}` }
    })
    .then(res => setAdminAppointments(res.data.data))
    .catch(() => handleLogout())
    .finally(() => setLoading(false));
  };

  // Cargar Pacientes
  const fetchPatients = () => {
    if (!token) return;
    setLoading(true);
    axios.get(`${API_BASE}/admin/patients`, {
      params: { search: patientSearch },
      headers: { Authorization: `Bearer ${token}` }
    })
    .then(res => setPatients(res.data.data))
    .finally(() => setLoading(false));
  };

  // Cargar Finanzas
  const fetchFinance = () => {
    if (!token || user?.role !== 'admin') return;
    setLoading(true);
    axios.get(`${API_BASE}/admin/reports/income`, {
      params: financeRange,
      headers: { Authorization: `Bearer ${token}` }
    })
    .then(res => setFinancialReport(res.data))
    .catch(() => alert("No tienes permisos para ver finanzas"))
    .finally(() => setLoading(false));
  };

  useEffect(() => {
    if (view === 'admin' && token) {
      if (adminSubView === 'agenda') fetchAgenda();
      if (adminSubView === 'patients') fetchPatients();
      if (adminSubView === 'finance') fetchFinance();
    }
  }, [view, adminSubView, adminDate, token, patientSearch, financeRange, calendarMode]);

  const loadPatientDetail = (id: string) => {
    if (id === '00000000-0000-0000-0000-000000000000') return; // Ignorar clics en bloqueos
    setLoading(true);
    axios.get(`${API_BASE}/admin/patients/${id}`, {
      headers: { Authorization: `Bearer ${token}` }
    })
    .then(res => setSelectedPatient(res.data))
    .finally(() => setLoading(false));
  };

  const saveMedicalRecord = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedPatient) return;
    setLoading(true);
    try {
      await axios.post(`${API_BASE}/admin/patients/${selectedPatient.patient.id}/medical-records`, newRecord, {
        headers: { Authorization: `Bearer ${token}` }
      });
      loadPatientDetail(selectedPatient.patient.id);
      setNewRecord({ visit_date: new Date().toISOString().split('T')[0], treatment: '', notes: '' });
      alert("Ficha actualizada correctamente");
    } catch (err) {
      alert("Error al guardar la ficha");
    } finally {
      setLoading(false);
    }
  };

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    try {
      const res = await axios.post(`${API_BASE}/admin/login`, loginForm);
      localStorage.setItem('token', res.data.token);
      localStorage.setItem('user', JSON.stringify(res.data.user));
      setToken(res.data.token);
      setUser(res.data.user);
      setView('admin');
    } catch (err) {
      alert("Credenciales incorrectas");
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = () => {
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    setToken(null);
    setUser(null);
    setView('public');
  };

  const markAttended = async (id: string) => {
    try {
      await axios.patch(`${API_BASE}/admin/appointments/${id}/attend`, {}, {
        headers: { Authorization: `Bearer ${token}` }
      });
      fetchAgenda();
    } catch (err) {
      alert("Error al marcar como atendido");
    }
  };

  const handleBlockSlot = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    try {
      await axios.post(`${API_BASE}/admin/appointments/block`, {
        professional_id: blockForm.professional_id || user.id,
        datetime: `${adminDate} ${blockForm.time}:00`,
        notes: blockForm.notes
      }, {
        headers: { Authorization: `Bearer ${token}` }
      });
      setShowBlockModal(false);
      fetchAgenda();
    } catch (err: any) {
      alert(err.response?.data?.message || "Error al bloquear horario");
    } finally {
      setLoading(false);
    }
  };

  const handleBooking = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    try {
      const res = await axios.post(`${API_BASE}/appointments`, {
        ...formData, professional_id: selectedProf?.id, datetime: selectedSlot?.datetime
      });
      setConfirmed(res.data);
      setStep(4);
    } catch (err: any) {
      alert(err.response?.data?.message || "Error al reservar");
    } finally {
      setLoading(false);
    }
  };

  const downloadReport = () => {
    window.open(`${API_BASE}/admin/reports/income/export?from=${financeRange.from}&to=${financeRange.to}&token=${token}`, '_blank');
  };

  return (
    <div className="container">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '30px' }}>
        <div>
          <h1 style={{ color: 'var(--primary)', fontSize: '1.8rem', cursor: 'pointer' }} onClick={() => setView('public')}>
            Podología Olivos
          </h1>
          <p style={{ color: 'var(--text-light)', fontSize: '0.9rem' }}>Sistema de Turnos Profesional</p>
        </div>
        
        {!token ? (
          <button onClick={() => setView('login')} style={btnSecondary}>
            <LogIn size={18} /> Acceso Staff
          </button>
        ) : (
          <div style={{ display: 'flex', gap: '8px' }}>
            <button onClick={() => { setAdminSubView('agenda'); setView('admin'); }} style={adminSubView === 'agenda' && view === 'admin' ? btnActive : btnSecondary}>
              <ClipboardList size={18} /> Agenda
            </button>
            <button onClick={() => { setAdminSubView('patients'); setView('admin'); setSelectedPatient(null); }} style={adminSubView === 'patients' && view === 'admin' ? btnActive : btnSecondary}>
              <Users size={18} /> Pacientes
            </button>
            {user?.role === 'admin' && (
              <button onClick={() => { setAdminSubView('finance'); setView('admin'); }} style={adminSubView === 'finance' && view === 'admin' ? btnActive : btnSecondary}>
                <TrendingUp size={18} /> Finanzas
              </button>
            )}
            <button onClick={handleLogout} style={{ ...btnSecondary, color: '#dc2626' }}><LogOut size={18} /></button>
          </div>
        )}
      </header>

      <main style={{ background: 'var(--white)', padding: '25px', borderRadius: '16px', boxShadow: 'var(--shadow)' }}>
        
        {/* VISTA: RESERVA PÚBLICA */}
        {view === 'public' && (
          <>
            {step === 1 && (
              <div>
                <h2 style={sectionTitle}><User size={22} color="var(--primary)" /> 1. Elegí tu podóloga</h2>
                <div style={{ display: 'grid', gap: '12px' }}>
                  {professionals.map(p => (
                    <button key={p.id} onClick={() => { setSelectedProf(p); setStep(2); }} style={profCard}>
                      <div>
                        <strong>{p.name}</strong>
                        <span style={{ display: 'block', fontSize: '0.8rem', color: 'var(--text-light)' }}>Sesión de {p.appointment_duration_min} min</span>
                      </div>
                      <ChevronRight size={20} color="var(--border)" />
                    </button>
                  ))}
                </div>
              </div>
            )}

            {step === 2 && (
              <div>
                <h2 style={sectionTitle}><Calendar size={22} color="var(--primary)" /> 2. Fecha y horario</h2>
                <input type="date" value={date} min={new Date().toISOString().split('T')[0]} onChange={e => setDate(e.target.value)} style={inputStyle} />
                <div style={gridSlots}>
                  {slots.map(s => (
                    <button 
                      key={s.time} 
                      disabled={!s.available} 
                      onClick={() => setSelectedSlot(s)}
                      style={s.available ? (selectedSlot?.time === s.time ? slotSelected : slotAvailable) : slotDisabled}
                    >
                      {s.time}
                    </button>
                  ))}
                </div>
                <div style={btnRow}>
                  <button onClick={() => setStep(1)} style={btnBack}>Volver</button>
                  <button disabled={!selectedSlot} onClick={() => setStep(3)} style={btnPrimary}>Continuar</button>
                </div>
              </div>
            )}

            {step === 3 && (
              <form onSubmit={handleBooking}>
                <h2 style={sectionTitle}><CheckCircle size={22} color="var(--primary)" /> 3. Tus datos</h2>
                <div style={{ display: 'grid', gap: '10px' }}>
                  <input required placeholder="DNI (Identificador Único)" value={formData.dni} onChange={e => setFormData({...formData, dni: e.target.value})} style={inputStyle} />
                  <input required placeholder="Nombre" value={formData.first_name} onChange={e => setFormData({...formData, first_name: e.target.value})} style={inputStyle} />
                  <input required placeholder="Apellido" value={formData.last_name} onChange={e => setFormData({...formData, last_name: e.target.value})} style={inputStyle} />
                  <input required placeholder="WhatsApp" value={formData.phone} onChange={e => setFormData({...formData, phone: e.target.value})} style={inputStyle} />
                  <input placeholder="Email (opcional)" value={formData.email} onChange={e => setFormData({...formData, email: e.target.value})} style={inputStyle} />
                </div>
                <div style={btnRow}>
                  <button type="button" onClick={() => setStep(2)} style={btnBack}>Volver</button>
                  <button type="submit" disabled={loading} style={btnPrimary}>{loading ? 'Reservando...' : 'Confirmar Turno'}</button>
                </div>
              </form>
            )}

            {step === 4 && confirmed && (
              <div style={{ textAlign: 'center' }}>
                <CheckCircle size={60} color="#22c55e" />
                <h2 style={{ margin: '15px 0' }}>¡Turno Confirmado!</h2>
                <p>{confirmed.message}</p>
                <button onClick={() => window.location.reload()} style={btnPrimary}>Hacer otra reserva</button>
              </div>
            )}
          </>
        )}

        {/* VISTA: LOGIN STAFF */}
        {view === 'login' && (
          <form onSubmit={handleLogin} style={{ maxWidth: '400px', margin: '0 auto' }}>
            <h2 style={{ textAlign: 'center', marginBottom: '20px' }}>Acceso al Sistema</h2>
            <div style={{ display: 'grid', gap: '15px' }}>
              <input required type="email" placeholder="Email" value={loginForm.email} onChange={e => setLoginForm({...loginForm, email: e.target.value})} style={inputStyle} />
              <input required type="password" placeholder="Contraseña" value={loginForm.password} onChange={e => setLoginForm({...loginForm, password: e.target.value})} style={inputStyle} />
              <button type="submit" disabled={loading} style={btnPrimary}>{loading ? 'Accediendo...' : 'Iniciar Sesión'}</button>
              <button type="button" onClick={() => setView('public')} style={btnBack}>Cancelar</button>
            </div>
          </form>
        )}

        {/* VISTA: ADMIN - AGENDA */}
        {view === 'admin' && adminSubView === 'agenda' && (
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '15px' }}>
                <h2 style={{ margin: 0 }}>Agenda</h2>
                <div style={{ display: 'flex', background: 'var(--bg-soft)', padding: '4px', borderRadius: '8px' }}>
                  <button 
                    onClick={() => setCalendarMode('daily')} 
                    style={calendarMode === 'daily' ? btnToggleActive : btnToggle}
                  >
                    Día
                  </button>
                  <button 
                    onClick={() => setCalendarMode('weekly')} 
                    style={calendarMode === 'weekly' ? btnToggleActive : btnToggle}
                  >
                    Semana
                  </button>
                </div>
              </div>
              <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
                <button onClick={() => { setBlockData({...blockForm, professional_id: user.id}); setShowBlockModal(true); }} style={{ ...btnSecondary, background: '#fee2e2', color: '#b91c1c' }}>
                  <ShieldAlert size={18} /> Bloquear
                </button>
                <input type="date" value={adminDate} onChange={e => setAdminDate(e.target.value)} style={{ ...inputStyle, width: 'auto', marginBottom: 0 }} />
              </div>
            </div>
            <table style={tableStyle}>
              <thead>
                <tr style={{ textAlign: 'left', background: '#f8fafc' }}>
                  {calendarMode === 'weekly' && <th style={thStyle}>Fecha</th>}
                  <th style={thStyle}>Hora</th>
                  <th style={thStyle}>Paciente / Motivo</th>
                  <th style={thStyle}>Profesional</th>
                  <th style={thStyle}>Estado</th>
                  <th style={thStyle}>Acción</th>
                </tr>
              </thead>
              <tbody>
                {adminAppointments.map(appt => (
                  <tr key={appt.id} style={{ ...trStyle, opacity: appt.status === 'blocked' ? 0.7 : 1 }}>
                    {calendarMode === 'weekly' && (
                      <td style={tdStyle}>
                        {new Date(appt.starts_at).toLocaleDateString([], { weekday: 'short', day: '2-digit', month: '2-digit' })}
                      </td>
                    )}
                    <td style={tdStyle}>{new Date(appt.starts_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                    <td style={tdStyle}>
                      {appt.status === 'blocked' ? (
                        <span style={{ color: '#b91c1c', fontStyle: 'italic' }}>[BLOQUEADO] {appt.notes}</span>
                      ) : (
                        <>
                          <span style={{ cursor: 'pointer', color: 'var(--primary)', fontWeight: 'bold' }} onClick={() => { setAdminSubView('patients'); loadPatientDetail(appt.patient.id); }}>
                            {appt.patient.full_name}
                          </span>
                          <span style={{ display: 'block', fontSize: '0.8rem' }}>{appt.patient.phone}</span>
                        </>
                      )}
                    </td>
                    <td style={tdStyle}>{appt.professional.name}</td>
                    <td style={tdStyle}><span style={badgeStyle(appt.status)}>{appt.status}</span></td>
                    <td style={tdStyle}>
                      {appt.status === 'confirmed' && <button onClick={() => markAttended(appt.id)} style={btnSmall}>Atendido</button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {/* MODAL DE BLOQUEO */}
            {showBlockModal && (
              <div style={modalOverlay}>
                <div style={modalContent}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '20px' }}>
                    <h3>Bloquear Horario</h3>
                    <button onClick={() => setShowBlockModal(false)} style={{ background: 'none', border: 'none' }}><X /></button>
                  </div>
                  <form onSubmit={handleBlockSlot}>
                    <label style={labelStyle}>Profesional</label>
                    <select 
                      disabled={user.role !== 'admin'}
                      value={blockForm.professional_id || user.id} 
                      onChange={e => setBlockData({...blockForm, professional_id: e.target.value})}
                      style={inputStyle}
                    >
                      {professionals.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>

                    <label style={labelStyle}>Hora de inicio</label>
                    <input type="time" value={blockForm.time} onChange={e => setBlockData({...blockForm, time: e.target.value})} style={inputStyle} />

                    <label style={labelStyle}>Motivo (opcional)</label>
                    <input placeholder="Ej: Trámite médico, almuerzo..." value={blockForm.notes} onChange={e => setBlockData({...blockForm, notes: e.target.value})} style={inputStyle} />

                    <div style={{ marginTop: '20px', display: 'grid', gap: '10px' }}>
                      <button type="submit" disabled={loading} style={{ ...btnPrimary, background: '#dc2626' }}>Confirmar Bloqueo</button>
                      <button type="button" onClick={() => setShowBlockModal(false)} style={btnBack}>Cancelar</button>
                    </div>
                  </form>
                </div>
              </div>
            )}
          </div>
        )}

        {/* VISTA: ADMIN - PACIENTES */}
        {view === 'admin' && adminSubView === 'patients' && !selectedPatient && (
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '20px' }}>
              <h2 style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>Pacientes</h2>
              <div style={{ position: 'relative' }}>
                <Search size={18} style={{ position: 'absolute', left: '10px', top: '12px', color: '#64748b' }} />
                <input placeholder="Buscar por nombre o tel..." value={patientSearch} onChange={e => setPatientSearch(e.target.value)} style={{ ...inputStyle, paddingLeft: '35px', width: '250px' }} />
              </div>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '15px' }}>
              {patients.map(p => (
                <div key={p.id} onClick={() => loadPatientDetail(p.id)} style={{ ...profCard, cursor: 'pointer', flexDirection: 'column', alignItems: 'flex-start' }}>
                  <strong>{p.full_name}</strong>
                  <span style={{ fontSize: '0.85rem', color: 'var(--text-light)' }}><Phone size={12} inline /> {p.phone}</span>
                  <span style={{ fontSize: '0.8rem', marginTop: '10px', color: 'var(--primary)' }}>Visitas: {p.visit_count}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* VISTA: ADMIN - DETALLE PACIENTE */}
        {view === 'admin' && adminSubView === 'patients' && selectedPatient && (
          <div>
            <button onClick={() => setSelectedPatient(null)} style={{ display: 'flex', alignItems: 'center', gap: '5px', background: 'none', border: 'none', color: 'var(--text-light)', marginBottom: '20px' }}>
              <ArrowLeft size={18} /> Volver a la lista
            </button>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: '30px' }}>
              
              {/* Info Personal */}
              <div style={{ background: '#f8fafc', padding: '20px', borderRadius: '12px' }}>
                <h3 style={{ marginBottom: '15px' }}>{selectedPatient.patient.full_name}</h3>
                <p style={{ marginBottom: '10px' }}><strong>DNI:</strong> {selectedPatient.patient.dni}</p>
                <p style={{ marginBottom: '10px' }}><Phone size={16} /> {selectedPatient.patient.phone}</p>
                <p style={{ marginBottom: '10px' }}><Mail size={16} /> {selectedPatient.patient.email || 'No posee'}</p>
                <p style={{ marginBottom: '10px' }}><Calendar size={16} /> FN: {selectedPatient.patient.dob || 'Sin datos'}</p>
                <hr style={{ margin: '15px 0', border: 'none', borderTop: '1px solid #e2e8f0' }} />
                <p><strong>Total Visitas:</strong> {selectedPatient.patient.visit_count}</p>
                <p><strong>Última:</strong> {selectedPatient.patient.last_visit_at || 'Nunca'}</p>
              </div>

              {/* Historial Médico */}
              <div>
                <h3 style={{ marginBottom: '15px', display: 'flex', alignItems: 'center', gap: '10px' }}>
                  <History size={20} color="var(--primary)" /> Historial Médico
                </h3>
                
                {/* Nueva Ficha */}
                <form onSubmit={saveMedicalRecord} style={{ background: '#f0f9ff', padding: '15px', borderRadius: '12px', marginBottom: '20px' }}>
                  <h4 style={{ marginBottom: '10px' }}>Nueva Evolución</h4>
                  <div style={{ display: 'grid', gap: '10px' }}>
                    <input type="date" value={newRecord.visit_date} onChange={e => setNewRecord({...newRecord, visit_date: e.target.value})} style={inputStyle} />
                    <input required placeholder="Tratamiento realizado..." value={newRecord.treatment} onChange={e => setNewRecord({...newRecord, treatment: e.target.value})} style={inputStyle} />
                    <textarea placeholder="Notas adicionales..." value={newRecord.notes} onChange={e => setNewRecord({...newRecord, notes: e.target.value})} style={{ ...inputStyle, minHeight: '60px' }} />
                    <button type="submit" disabled={loading} style={{ ...btnPrimary, width: '100%' }}>Guardar en Ficha</button>
                  </div>
                </form>

                <div style={{ display: 'grid', gap: '15px' }}>
                  {selectedPatient.medical_records.map((r: MedicalRecord) => (
                    <div key={r.id} style={{ border: '1px solid #e2e8f0', padding: '15px', borderRadius: '10px' }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '8px' }}>
                        <strong>{r.visit_date}</strong>
                        <span style={{ fontSize: '0.8rem', color: 'var(--text-light)' }}>Atendió: {r.professional.name}</span>
                      </div>
                      <p style={{ color: 'var(--primary)', fontWeight: 'bold' }}>{r.treatment}</p>
                      <p style={{ fontSize: '0.9rem', color: '#64748b', marginTop: '5px' }}>{r.notes}</p>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* VISTA: ADMIN - FINANZAS */}
        {view === 'admin' && adminSubView === 'finance' && financialReport && (
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
              <h2 style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <TrendingUp color="var(--primary)" /> Finanzas y Comisiones
              </h2>
              <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
                <input type="date" value={financeRange.from} onChange={e => setFinanceRange({...financeRange, from: e.target.value})} style={{ ...inputStyle, width: 'auto', marginBottom: 0 }} />
                <span>a</span>
                <input type="date" value={financeRange.to} onChange={e => setFinanceRange({...financeRange, to: e.target.value})} style={{ ...inputStyle, width: 'auto', marginBottom: 0 }} />
                <button onClick={downloadReport} style={btnSecondary} title="Descargar CSV"><Download size={18} /></button>
              </div>
            </div>

            {/* Tarjetas de Resumen */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '20px', marginBottom: '30px' }}>
              <div style={statCard}>
                <span style={statLabel}>Ingresos Totales</span>
                <span style={statValue}>${Number(financialReport.totals.total_income).toLocaleString()}</span>
                <span style={statSub}>{financialReport.totals.total_appointments} turnos</span>
              </div>
              <div style={{ ...statCard, borderLeft: '4px solid #10b981' }}>
                <span style={statLabel}>Caja Consultorio</span>
                <span style={statValue}>${Number(financialReport.totals.total_owner).toLocaleString()}</span>
              </div>
              <div style={{ ...statCard, borderLeft: '4px solid #3b82f6' }}>
                <span style={statLabel}>Pago Profesionales</span>
                <span style={statValue}>${Number(financialReport.totals.total_employee).toLocaleString()}</span>
              </div>
            </div>

            {/* Desglose por Profesional */}
            <h3 style={{ marginBottom: '15px' }}>Desglose por Profesional</h3>
            <table style={tableStyle}>
              <thead>
                <tr style={{ textAlign: 'left', background: '#f8fafc' }}>
                  <th style={thStyle}>Profesional</th>
                  <th style={thStyle}>Turnos</th>
                  <th style={thStyle}>Total Generado</th>
                  <th style={thStyle}>Comisión (%)</th>
                  <th style={thStyle}>Pago Profesional</th>
                  <th style={thStyle}>Neto Consultorio</th>
                </tr>
              </thead>
              <tbody>
                {financialReport.by_professional.map(p => (
                  <tr key={p.user_id} style={trStyle}>
                    <td style={tdStyle}>
                      <strong>{p.professional.name}</strong>
                      <span style={{ display: 'block', fontSize: '0.75rem', color: '#64748b' }}>{p.professional.role.toUpperCase()}</span>
                    </td>
                    <td style={tdStyle}>{p.appointments}</td>
                    <td style={tdStyle}>${Number(p.income).toLocaleString()}</td>
                    <td style={tdStyle}>{p.professional.role === 'admin' ? '-' : '50%'}</td>
                    <td style={tdStyle}>${Number(p.employee_share).toLocaleString()}</td>
                    <td style={tdStyle}><strong>${Number(p.owner_share).toLocaleString()}</strong></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </main>

      <footer style={{ marginTop: '30px', textAlign: 'center', fontSize: '0.8rem', color: 'var(--text-light)' }}>
        Sesión: {user ? `${user.name} (${user.role})` : 'Visitante'}
      </footer>
    </div>
  );
}

// --- ESTILOS ---
const sectionTitle = { marginBottom: '20px', display: 'flex', alignItems: 'center', gap: '10px', fontSize: '1.2rem' };
const inputStyle = { width: '100%', padding: '12px', borderRadius: '8px', border: '1px solid var(--border)', marginBottom: '5px' };
const profCard = { width: '100%', padding: '15px', border: '1px solid var(--border)', borderRadius: '10px', background: 'none', display: 'flex', justifyContent: 'space-between', alignItems: 'center' };
const gridSlots = { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(90px, 1fr))', gap: '10px', marginTop: '10px' };
const btnPrimary = { background: 'var(--primary)', color: 'white', padding: '10px 25px', borderRadius: '8px', border: 'none', fontWeight: 'bold' };
const btnSecondary = { background: 'var(--bg-soft)', color: 'var(--primary)', padding: '8px 15px', borderRadius: '8px', border: 'none', display: 'flex', alignItems: 'center', gap: '8px', fontSize: '0.9rem' };
const btnActive = { ...btnSecondary, background: 'var(--primary)', color: 'white' };
const btnBack = { background: 'none', border: 'none', color: 'var(--text-light)', fontWeight: '500' };
const btnRow = { marginTop: '20px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' };
const slotAvailable = { padding: '10px', borderRadius: '6px', border: '1px solid var(--border)', background: 'white' };
const slotSelected = { padding: '10px', borderRadius: '6px', border: '1px solid var(--primary)', background: 'var(--primary)', color: 'white' };
const slotDisabled = { padding: '10px', borderRadius: '6px', border: '1px solid #eee', background: '#f9f9f9', color: '#ccc', cursor: 'not-allowed' };
const tableStyle = { width: '100%', borderCollapse: 'collapse', marginTop: '10px' };
const thStyle = { padding: '12px', borderBottom: '2px solid #e2e8f0', color: '#475569', fontSize: '0.85rem' };
const tdStyle = { padding: '12px', borderBottom: '1px solid #e2e8f0', fontSize: '0.9rem' };
const trStyle = { transition: 'background 0.2s' };
const btnSmall = { padding: '5px 10px', borderRadius: '5px', background: '#10b981', color: 'white', border: 'none', fontSize: '0.75rem' };
const badgeStyle = (status: string) => ({
  padding: '4px 8px', borderRadius: '10px', fontSize: '0.7rem', fontWeight: '600',
  background: status === 'attended' ? '#dcfce7' : (status === 'confirmed' ? '#e0f2fe' : (status === 'blocked' ? '#f1f5f9' : '#fee2e2')),
  color: status === 'attended' ? '#166534' : (status === 'confirmed' ? '#0369a1' : (status === 'blocked' ? '#475569' : '#991b1b'))
});

const statCard = { padding: '20px', background: '#f8fafc', borderRadius: '12px', display: 'flex', flexDirection: 'column', gap: '5px', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' };
const statLabel = { fontSize: '0.85rem', color: '#64748b', fontWeight: '500' };
const statValue = { fontSize: '1.5rem', fontWeight: 'bold', color: '#1e293b' };
const statSub = { fontSize: '0.75rem', color: '#94a3b8' };

const modalOverlay = { position: 'fixed' as 'fixed', top: 0, left: 0, right: 0, bottom: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 };
const modalContent = { background: 'white', padding: '30px', borderRadius: '16px', width: '100%', maxWidth: '400px', boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)' };
const labelStyle = { display: 'block', marginBottom: '5px', fontSize: '0.9rem', fontWeight: '500', color: '#475569' };

const btnToggle = { 
  padding: '6px 12px', borderRadius: '6px', border: 'none', background: 'transparent', 
  fontSize: '0.85rem', fontWeight: '600', color: '#64748b', cursor: 'pointer' 
};
const btnToggleActive = { 
  ...btnToggle, background: 'white', color: 'var(--primary)', boxShadow: '0 1px 2px rgba(0,0,0,0.1)' 
};

export default App;
