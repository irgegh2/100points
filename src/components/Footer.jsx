import { brand, footer } from '../data/content.js'

export default function Footer() {
  return (
    <footer className="footer">
      <div className="container">
        <div className="footer__grid">
          <div className="footer__logo"><img src="/img/logo.svg" alt={brand.name} /></div>
          {footer.columns.map((col) => (
            <div className="footer__col" key={col.title}>
              <h5>{col.title}</h5>
              {col.links.map((l) => <a key={l.label} href={l.href}>{l.label}</a>)}
            </div>
          ))}
          <div className="footer__contacts">
            <b>{footer.phone}</b>
            <a href={`mailto:${footer.email}`}>{footer.email}</a>
            <div className="footer__pay">
              <span>МИР</span><span>VISA</span><span>MC</span>
            </div>
          </div>
        </div>
        <div className="footer__bottom">
          <span>© {brand.name}. Онлайн-школа подготовки к ЕГЭ и ОГЭ.</span>
          <span><a href="/privacy">Политика конфиденциальности</a> · <a href="/offer">Договор-оферта</a></span>
        </div>
      </div>
    </footer>
  )
}
