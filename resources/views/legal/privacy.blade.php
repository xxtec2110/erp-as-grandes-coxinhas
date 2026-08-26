@extends('layouts.guest')

@section('content')
    <article class="w-full max-w-4xl rounded-3xl bg-white px-6 py-8 shadow-xl shadow-amber-950/10 sm:px-10 sm:py-12">
        <header class="border-b border-stone-200 pb-8">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">As Grandes Coxinhas</p>
            <h1 class="mt-3 text-3xl font-bold tracking-tight text-stone-950 sm:text-4xl">Política de Privacidade</h1>
            <p class="mt-4 text-sm text-stone-600">Última atualização: 26 de agosto de 2026.</p>
        </header>

        <div class="mt-8 space-y-8 text-base leading-7 text-stone-700">
            <section>
                <h2 class="text-xl font-bold text-stone-950">1. Sobre esta política</h2>
                <p class="mt-3">
                    Esta Política de Privacidade descreve como o ERP As Grandes Coxinhas trata dados pessoais e
                    operacionais necessários para autenticação, gestão empresarial, integrações autorizadas,
                    segurança, auditoria e suporte.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-bold text-stone-950">2. Dados tratados</h2>
                <p class="mt-3">Conforme os recursos utilizados, o sistema pode tratar:</p>
                <ul class="mt-3 list-disc space-y-2 pl-6">
                    <li>dados de identificação, contato, autenticação, perfil e permissões dos usuários;</li>
                    <li>dados administrativos, financeiros, comerciais e operacionais registrados no ERP;</li>
                    <li>conteúdo e metadados de mensagens recebidas ou enviadas por canais integrados e autorizados;</li>
                    <li>registros técnicos de acesso, segurança, auditoria, erros e uso das funcionalidades.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-bold text-stone-950">3. Finalidades</h2>
                <p class="mt-3">
                    Os dados são utilizados para controlar o acesso ao ERP, executar processos empresariais,
                    disponibilizar integrações solicitadas, manter a rastreabilidade das operações, prevenir uso
                    indevido, atender obrigações legais e prestar suporte aos usuários autorizados.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-bold text-stone-950">4. Integrações e compartilhamento</h2>
                <p class="mt-3">
                    Dados podem ser compartilhados, no limite necessário, com provedores que sustentam a hospedagem,
                    o WhatsApp Business da Meta, serviços de inteligência artificial habilitados pela empresa e
                    integrações oficiais de sistemas de venda. Cada integração somente é utilizada quando configurada
                    e autorizada. Também poderá ocorrer compartilhamento para cumprimento de obrigação legal ou ordem
                    de autoridade competente.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-bold text-stone-950">5. Segurança e conservação</h2>
                <p class="mt-3">
                    São adotadas medidas técnicas e administrativas adequadas para restringir acessos, proteger
                    credenciais, registrar operações sensíveis e reduzir riscos de perda, alteração ou divulgação
                    indevida. Os dados são conservados pelo período necessário às finalidades informadas, às obrigações
                    legais e à defesa de direitos.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-bold text-stone-950">6. Direitos e contato</h2>
                <p class="mt-3">
                    Solicitações relativas a acesso, correção, atualização, oposição ou exclusão de dados pessoais
                    podem ser encaminhadas pelos canais oficiais de atendimento das As Grandes Coxinhas. O atendimento
                    observará a identidade do solicitante, a legislação aplicável e os deveres de guarda e auditoria.
                </p>
            </section>

            <footer class="border-t border-stone-200 pt-6 text-sm text-stone-600">
                Esta política poderá ser atualizada para refletir mudanças legais, operacionais ou nas integrações do
                ERP. A versão vigente estará sempre disponível nesta página.
            </footer>
        </div>
    </article>
@endsection
