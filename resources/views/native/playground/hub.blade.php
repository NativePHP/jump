<list :separator="true" class="w-full h-full bg-theme-background">
    @foreach($groups as $group)
        <list-section :native:key="$group['title']" :header="$group['title']">
            @foreach($group['demos'] as $demo)
                <list-item
                    :native:key="$demo['id']"
                    @press="navigate('{{ $demo['url'] }}')"
                    :leadingIcon="$demo['icon']"
                    :leadingIconBgColor="$demo['color']"
                    :headline="$demo['title']"
                    :supporting="$demo['subtitle']"
                    trailingIcon="chevron.right"
                />
            @endforeach
        </list-section>
    @endforeach
</list>
