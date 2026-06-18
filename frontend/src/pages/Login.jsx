import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { login } from '../api/axios.js'
import toast from 'react-hot-toast'
import { Eye, EyeOff, LogIn } from 'lucide-react'

export default function Login() {
  const navigate     = useNavigate()
  const [form, setForm]   = useState({ email: '', password: '' })
  const [loading, setLoading] = useState(false)
  const [showPass, setShowPass] = useState(false)

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!form.email || !form.password) return toast.error('Enter email and password.')
    setLoading(true)
    try {
      const res = await login(form.email, form.password)
      if (res.data.success) {
        localStorage.setItem('lead8x_token', res.data.data.token)
        localStorage.setItem('lead8x_user', JSON.stringify(res.data.data.user))
        toast.success(`Welcome, ${res.data.data.user.name}!`)
        navigate('/')
      }
    } catch (err) {
      toast.error(err.response?.data?.message || 'Login failed. Check credentials.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-page">
      {/* Left hero */}
      <div className="login-left">
        <div className="login-hero">
          <div style={{ fontSize:'3.5rem', marginBottom:'10px' }}>⚡</div>
          <h1>Lead8X</h1>
          <p>Real estate lead management & distribution platform — built for speed, accuracy, and zero data loss.</p>
          <ul>
            <li>Manage 100,000+ leads with confidence</li>
            <li>Intelligent duplicate detection</li>
            <li>Equal & manual lead distribution</li>
            <li>Full audit trail & timeline</li>
            <li>One-click backup & restore</li>
          </ul>
        </div>
      </div>

      {/* Right form */}
      <div className="login-right">
        <div className="login-form-container">
          <h2>Sign in to Lead8X 👋</h2>
          <p>Sign in to your Lead8X account</p>

          <form onSubmit={handleSubmit}>
            <div className="form-group">
              <label className="form-label">Email Address</label>
              <input
                id="login-email"
                className="form-input"
                type="email"
                placeholder=""
                value={form.email}
                onChange={e => setForm(f => ({ ...f, email: e.target.value }))}
                autoFocus
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">Password</label>
              <div style={{ position:'relative' }}>
                <input
                  id="login-password"
                  className="form-input"
                  type={showPass ? 'text' : 'password'}
                  placeholder="Enter your password"
                  value={form.password}
                  style={{ paddingRight: '44px' }}
                  onChange={e => setForm(f => ({ ...f, password: e.target.value }))}
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowPass(s => !s)}
                  style={{ position:'absolute', right:'12px', top:'50%', transform:'translateY(-50%)', background:'none', border:'none', color:'var(--text-muted)', cursor:'pointer' }}
                >
                  {showPass ? <EyeOff size={17}/> : <Eye size={17}/>}
                </button>
              </div>
            </div>

            <button
              id="login-submit"
              className="btn btn-primary btn-lg w-full"
              type="submit"
              disabled={loading}
            >
              {loading ? <span className="spinner" style={{width:18,height:18,borderWidth:2}} /> : <LogIn size={18}/>}
              {loading ? 'Signing in…' : 'Sign In'}
            </button>
          </form>

          <p className="login-divider" style={{ marginTop:'24px', fontSize:'0.78rem', color:'var(--text-muted)' }}>
            Lead8X v1.0 · digital8x.site
          </p>
        </div>
      </div>
    </div>
  )
}
