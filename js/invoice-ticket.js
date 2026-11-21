(() => {
    const ticket=document.querySelector('.invoice-ticket');if(!ticket)return;
    const style=document.createElement('style');style.media='print';document.head.append(style);
    const prepare=()=>{
        const width=document.body.dataset.ticketWidth==='58'?58:80;
        // Match the printable width while measuring, so long descriptions retain their height.
        const original=ticket.style.cssText;ticket.style.width=(width-6)+'mm';ticket.style.padding='0';
        const height=Math.max(30,Math.ceil(ticket.getBoundingClientRect().height*25.4/96)+8);
        ticket.style.cssText=original;style.textContent='@page { size: '+width+'mm '+height+'mm; margin: 3mm; }';
    };
    window.addEventListener('beforeprint',prepare);
    document.querySelector('[data-print-ticket]')?.addEventListener('click',async()=>{await document.fonts.ready;await Promise.all([...ticket.images].map(img=>img.decode().catch(()=>{})));prepare();window.print();});
})();
