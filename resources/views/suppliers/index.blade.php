@extends('layouts.app')
@section('title', 'Fornecedores')
@section('content')
    <div class="page-header">
        <div><h1 class="page-title">Fornecedores</h1><p class="page-subtitle">Cadastre quem fornece os insumos da produção.</p></div>
        <a class="btn-primary" href="{{ route('suppliers.create') }}">Novo fornecedor</a>
    </div>

    <div class="table-card">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Nome</th><th>CNPJ</th><th>Contato</th><th>Telefone</th><th>Status</th><th class="text-right">Ação</th></tr></thead>
                <tbody>
                    @forelse ($suppliers as $supplier)
                        <tr>
                            <td class="font-semibold">{{ $supplier->name }}</td>
                            <td>{{ $supplier->document_number ?: '—' }}</td>
                            <td>{{ $supplier->contact_name ?: '—' }}</td>
                            <td>{{ $supplier->phone ?: '—' }}</td>
                            <td><span class="status-badge {{ $supplier->active ? 'status-active' : 'status-inactive' }}">{{ $supplier->active ? 'Ativo' : 'Inativo' }}</span></td>
                            <td class="text-right"><a class="text-link" href="{{ route('suppliers.edit', $supplier) }}">Editar</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-state">Nenhum fornecedor cadastrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-5">{{ $suppliers->links() }}</div>
@endsection
