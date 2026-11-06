/* All quotation terms use artwork taken from the supplied Vibes PDFs. */
(() => {
  'use strict';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const round = value => Math.round((Number(value) + Number.EPSILON) * 100) / 100;
  const money = (value, currency) => `${currency} ${Number(value).toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2})}`;
  const itemMonths = item => {
    if (item.durationMonths != null) return Math.max(0, Number(item.durationMonths));
    const term = String(item.term || item.billing || 'once');
    return term === 'year' ? 12 : /^m\d+$/.test(term) ? Number(term.slice(1)) : term === 'month' ? 1 : 0;
  };
  const date = value => {
    const day = String(value || '').slice(0,10);
    return /^\d{4}-\d{2}-\d{2}$/.test(day) ? new Date(day+'T12:00:00').toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '';
  };
  function figures(quote) {
    const rows = (quote.items || []).map(item => {
      const months = Math.max(1, itemMonths(item));
      const rate = Math.min(99, Math.max(0, Number(item.durationDiscount ?? item.annualDiscount ?? 0)));
      // Existing records store the discounted contract unit price. Restore the
      // base price for display only, so approved financial records never change.
      const total = round(Number(item.total ?? Number(item.quantity)*Number(item.unitPrice)) / (1-rate/100));
      // Master quotations store the complete contract scope, while monthly
      // quotations keep the recurring monthly quantity. Do not multiply a
      // master quantity a second time when rendering its reference PDF.
      const quantity = item.quantityScope === 'contract'
        ? Number(item.quantity || 1)
        : Number(item.quantity || 1)*months;
      return {label:item.label || '', description:item.description || item.name || '', quantity, unitPrice:total/quantity, total, rate, months};
    });
    const subtotal = round(rows.reduce((sum,item)=>sum+item.total,0));
    const vat = round(subtotal*Number(quote.vat_rate || 0)/100);
    const total = Number(quote.total ?? subtotal+vat);
    const discount = round(subtotal+vat-total);
    return {rows,subtotal,vat,discount,total};
  }
  function render(quote, background) {
    const source = quote.items || [];
    const terms = [...new Set(source.map(itemMonths))];
    // The master quotation carries the contract term separately from each
    // catalog row. Prefer that explicit contract term so a six-month master
    // cannot accidentally fall back to the one-time artwork just because a
    // service row itself is not recurring. Monthly child quotations are
    // deliberately rendered as one-time pages.
    const explicitMonths = Number(quote.contract_months ?? quote.duration_months ?? 0);
    const periodIndex = Number(quote.period_index ?? 0);
    const months = periodIndex > 0
      ? 0
      : ([0,6,12].includes(explicitMonths) ? explicitMonths : (terms.length === 1 ? terms[0] : 0));
    if (!source.length || ![0,6,12].includes(months)) return '';
    const once = months === 0;
    const kind = once ? 'one-time' : months === 12 ? 'one-year' : 'six-month';
    const termLabel = once ? 'One-time project' : months === 12 ? '1 year' : '6 months';
    background = typeof background === 'object' ? background[kind] : String(background).replace('quotation-six-month-background.png',`quotation-${kind}-background.png`);
    const data = figures(quote), currency = quote.currency === 'USD' ? 'USD' : 'SAR';
    const rates = [...new Set(data.rows.map(item=>item.rate))];
    const rate = rates.length===1 ? rates[0] : 0;
    // Use the reference positions in PDF points, keeping A4 proportions on
    // desktop, mobile and printed pages. Long quotations continue on new sheets.
    // The long-term reference has a start/end-date band between the contract
    // summary and the service table; leave room for it on the first page.
    const firstStart = once ? 294.53 : 402;
    const preparedRows = data.rows.map(item=>{
      const lines=[];let line='';
      for(const word of item.description.split(/\s+/).flatMap(word=>word.match(/.{1,54}/gu)||[])) {
        if(line && (line+' '+word).length>54) {lines.push(line);line=word;} else line+=(line?' ':'')+word;
      }
      if(line) lines.push(line);
      const height=Math.max(31.181,lines.length*9+12);
      return {...item,lines,height};
    });
    const pages=[];
    let rowOffset=0;
    while(rowOffset<preparedRows.length) {
      const start=pages.length?91:firstStart;
      const remaining=preparedRows.slice(rowOffset);
      const fitsFinal=remaining.reduce((sum,item)=>sum+item.height,0)<=620-start;
      let count=remaining.length;
      if(!fitsFinal) {
        let used=0;count=0;
        // Keep at least one service with the final totals. Intermediate pages
        // can use the space that would otherwise be reserved for the summary.
        while(count<remaining.length-1 && used+remaining[count].height<=724-start) {
          used+=remaining[count].height;count++;
        }
        count=Math.max(1,count);
      }
      pages.push({rows:remaining.slice(0,count),start:rowOffset});rowOffset+=count;
    }
    return `<article class="vtp-quote-paper vtp-reference-paper" aria-label="${esc(quote.quote_number || 'Quotation')}">${pages.map(({rows,start:rowOffset},pageIndex)=>{
      let svg='';
      const text=(value,x,top,size=6.8,bold=false,color='#181126',right=false)=>{
        svg+=`<text x="${x}" y="${top+size*.78}" font-size="${size}" font-weight="${bold?700:400}" fill="${color}"${right?` text-anchor="${right==='middle'?'middle':'end'}"`:''}>${esc(value)}</text>`;
      };
      const continuation=pageIndex>0;
      if (!continuation) {
      text(date(quote.created_at),546.32,74.29,8.7,true,'#181126',true);
      text(date(quote.valid_until),546.32,93.3,8.7,true,'#181126',true);
      text(currency,546.32,109.99,8.7,true,'#181126',true);
      const client=quote.company || quote.client_name || '';
      text(client,58.55,161.95,Math.min(14.24,210/Math.max(1,client.length)*1.8));
      if (quote.country || quote.location) text(quote.country || quote.location,58.55,185.26,8.22,false,'#806398');
      text(quote.email || '',58.55,199.24,8.22,false,'#806398');
      text(quote.phone || '',58.55,213.21,8.22,false,'#806398');
      if (!once) {
      text('CONTRACT TERM',58.55,273.98,8.5,false,'#6c6179');
      text('DISCOUNT',194.61,273.98,8.5,false,'#6c6179');
      text('Prices shown cover the full contract term and include',330.68,273.98,8.5,false,'#6c6179');
      text('the applicable contract discount.',330.68,285.98,8.5,false,'#6c6179');
      text(termLabel,58.55,283.12,14,true,'#60338d');
      text(`${rate}% included`,194.61,283.12,14,true,'#60338d');
      const startDate = date(quote.contract_start || quote.start_date);
      const endDate = date(quote.contract_end || quote.end_date);
      svg+='<rect x="46.52" y="313" width="250" height="35" fill="#faf8fc"/><rect x="300" y="313" width="248" height="35" fill="#faf8fc"/>';
      text('START DATE',58.55,321,8.5,true,'#60338d');
      text(startDate || '—',285.5,321,10,true,'#6c6179',true);
      text('END DATE',312,321,8.5,true,'#60338d');
      text(endDate || '—',546.32,321,10,true,'#6c6179',true);
      }
      } else {
        // Continuation pages go directly into the service table without branding.
        svg+='<rect x="46.52" y="52" width="502.235" height="28" rx="9" fill="#60338d"/>';
        [['NO.',73.7],['LABEL',102.05],['SERVICE / DELIVERABLE',153.07],['QTY',372.76],['UNIT PRICE',421.24],['TOTAL',500.91]].forEach(([label,x])=>text(label,x,63,6.2,true,'#ffffff'));
      }
      let cursor=continuation?91:firstStart;
      rows.forEach((item,index)=>{
        const {lines,height}=item, top=cursor+(height-lines.length*9)/2;
        text(rowOffset+index+1,73.7,top,7,false,'#806398');
        text(item.label,104.88,top,7,true,'#60338d');
        lines.forEach((line,i)=>text(line,161.57,top+i*9));
        text(Number(item.quantity.toFixed(4)),385.51,top,6.8,false,'#181126',true);
        text(money(item.unitPrice,currency),456.38,top+.21,6.4,false,'#181126',true);
        text(money(item.total,currency),521.57,top+.21,6.4,false,'#181126',true);
        cursor+=height;
        svg+=`<line x1="65.197" x2="530.079" y1="${cursor-6.4}" y2="${cursor-6.4}" stroke="#d7775f" stroke-width=".5"/>`;
      });
      if(pageIndex===pages.length-1) {
        const shift=continuation ? cursor+16-(once?389.8:467.5) : Math.max(0,cursor-(once?388.073:455.543));
        const hasDiscount = !once || data.discount > 0;
        const y=(once?389.8:467.5)+shift;
        svg+=`<rect x="382.68" y="${y}" width="147.4" height="${once&&!hasDiscount?99.2:112.9}" rx="11.5" fill="#ffffff" stroke="#e8dcef" stroke-width=".7"/>`;
        const summary=once ? [['Subtotal',data.subtotal,408.96], [`VAT (${Number(quote.vat_rate)}%)`,data.vat,434.47], ...(hasDiscount?[[`Discount (${rate}%)`,data.discount,459.73]]:[]), ['TOTAL',data.total,hasDiscount?486.5:459.73]] : [['Subtotal',data.subtotal,481.29], [`VAT (${Number(quote.vat_rate)}%)`,data.vat,506.8], [`Discount (${rate}%)`,data.discount,533.62], ['TOTAL',data.total,561.23]];
        summary.forEach(([label,value,top],i)=>{
          const isTotal=i===summary.length-1;
          text(label,396.85,top+shift,isTotal?8:7.5,isTotal,isTotal?'#60338d':'#806398');
          text((months===12 && label.startsWith('Discount')?'-':'')+money(value,currency),515.91,top+shift,isTotal?8:7.5,isTotal,isTotal?'#60338d':'#181126',true);
          const lineTop=(once?[423.62,449.13,475.95]:[495.951,521.463,548.287])[i]+shift;
          if(!isTotal) svg+=`<line x1="396.85" x2="515.905" y1="${lineTop}" y2="${lineTop}" stroke="#e8dcef" stroke-width=".7"/>`;
        });
        if (once) {
          text('NOTES',65.2,400.82+shift,6.8,true,'#60338d');
          text('A tailored creative package for a focused, consistent launch.',65.2,411.56+shift,7.5,false,'#806398');
        }
      }
      if (continuation) {
        if(pageIndex===pages.length-1) {
          text('TERMS',65.2,752.94,6.8,true,'#60338d');
          const terms=once ? [
            'Work begins after written approval. Payment is due after final delivery according to the agreed payment term.',
            'Additional scope may require a revised quotation.',
          ] : [
            'Work begins after written approval.',
            "Payment is due monthly upon submission of each month's completed services.",
            'Additional scope may require a revised quotation.',
          ];
          terms.forEach((line,i)=>{
            svg+=`<rect x="65.294" y="${762.63+i*9.17}" width="2.824" height="2.824" fill="#d87860"/>`;
            text(line,71.9,761.37+i*9.17,7.5,false,'#806398');
          });
        }
        [['#60338d',46.52],['#8b75b1',213.932],['#d87860',381.344]].forEach(([color,x])=>{svg+=`<rect x="${x}" y="795.343" width="167.412" height="3" fill="${color}"/>`;});
        text('We don’t follow trends,',65.2,809.39,8);
        text('WE SET VIBES.',148.35,809.39,8,true);
        text('vibesagency.co',530.079,809.39,8,false,'#60338d',true);
      } else if(pages.length>1) {
        // The supplied first-page artwork contains terms; show them only with
        // the final summary when this quotation continues on another page.
        svg+='<rect x="60" y="748" width="490" height="43" fill="#ffffff"/>';
      }
      text(`Page ${pageIndex+1} of ${pages.length}`,297.64,809.39,7,false,'#806398','middle');
      const artwork=continuation?'':`<image href="${esc(background)}" width="595.276" height="841.89"/>`;
      return `<svg class="vtp-reference-sheet" viewBox="0 0 595.276 841.89" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="${termLabel} quotation, page ${pageIndex+1}" font-family="Arial, Helvetica, sans-serif">${artwork}${svg}</svg>`;
    }).join('')}</article>`;
  }
  function sample(term='once') {
    const months=term==='year'?12:term==='m6'?6:0;
    const rate=months===12?10:months===6?5:0;
    const services=months===12 ? [
      ['SOC','Standard static post',8,50],['VID','Basic reel edit',4,300],['MOT','Animated story and motion asset',1,600],
    ] : months===6 ? [
      ['SOC','Standard static post',8,50],['VID','Basic reel edit',2,300],['POD','Podcast episode production',1,1200],
    ] : [
      ['BRD','Logo design',1,2500],['SOC','Standard static post',8,50],['VID','Basic reel edit',2,300],
    ];
    const items=services.map(([label,description,quantity,price])=>{
      const unitPrice=round(price*Math.max(1,months)*(1-rate/100));
      return {label,description,quantity,unitPrice,total:round(quantity*unitPrice),term,durationMonths:months,durationDiscount:rate};
    });
    const subtotal=round(items.reduce((sum,item)=>sum+item.total,0));
    const issued=new Date(),valid=new Date();valid.setDate(valid.getDate()+14);
    const localDate=value=>`${value.getFullYear()}-${String(value.getMonth()+1).padStart(2,'0')}-${String(value.getDate()).padStart(2,'0')}`;
    return {quote_number:`VQ-SAMPLE-${months||'ONCE'}`,client_name:'Sample Client',email:'client@example.com',phone:'+966 5X XXX XXXX',created_at:localDate(issued),valid_until:localDate(valid),currency:'SAR',vat_rate:15,subtotal,vat_amount:round(subtotal*.15),total:round(subtotal+round(subtotal*.15)),items};
  }
  window.VTPQuoteReference={render,figures,sample};
})();
