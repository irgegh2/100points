import { brand, nav } from '../data/content.js'
import { lkUrl } from '../auth.js'

export default function Header() {
  return (
    <>
      <header className="header">
        <div className="container header__row">
          <a className="logo" href="/">
            <img src="/img/logo.svg" alt={brand.name} />
          </a>
          <nav className="nav">
            {nav.map((n) => (
              <a key={n.label} href={n.href}>{n.label}</a>
            ))}
          </nav>
          <div className="header__actions">
            <a className="btn btn-orange" href="#courses">Подобрать курс</a>
            <a className="btn btn-light" href={lkUrl('/login')}><span>Войти&nbsp;</span>→</a>
          </div>
        </div>
      </header>
    </>
  )
}
